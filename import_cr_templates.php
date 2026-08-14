<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI para importar plantillas (XML exportados) del bloque "Configurable Reports"
 * en una lista concreta de cursos.
 *
 * Este script se sube por SFTP a /tmp y se ejecuta por SSH como el usuario web
 * (p.ej. www-data). Reutiliza la propia lógica del plugin (xmlize + cr_unserialize
 * + cr_serialize) para que la serialización quede EXACTAMENTE igual que cuando se
 * importa a mano desde managereport.php.
 *
 * Es idempotente: si en el curso ya existe un reporte con el mismo nombre, lo omite
 * (o lo actualiza si se pasa --force). Así se puede re-ejecutar sin duplicar.
 *
 * Modo migración (--wipe): en vez de comparar por nombre, ANTES de importar
 * las plantillas se borran TODOS los reportes propios que ya existan en ese
 * curso (por si se re-ejecuta la migración). Pensado para crear de una vez
 * las plantillas propias en un curso detectado por audit_cr.php. Queda
 * registrado en el resultado como un ítem de estado "wiped" con el número de
 * reportes eliminados. (El plugin alquilado, block_advanced_reports, tiene
 * sus propias tablas: este script nunca las toca.)
 *
 * --addblock: además, ANTES de importar, agrega la instancia del bloque
 * "configurable_reports" a la página del curso si todavía no está (mismo
 * efecto que "Activar edición → Agregar un bloque" desde la interfaz). Queda
 * registrado como un ítem de estado "block_added" o "block_present".
 *
 * Salida: imprime un bloque JSON entre marcadores para que el orquestador Python
 * lo parsee de forma fiable (ignorando cualquier ruido de stdout):
 *
 *   <<<CR_RESULT>>>{...json...}<<<END_CR_RESULT>>>
 *
 * Parámetros (se parsean ANTES de arrancar Moodle, no usan clilib):
 *   --config=/ruta/a/moodle/config.php   (obligatorio)
 *   --dir=/tmp/cr_tpl_xxx                 (obligatorio: carpeta con los .xml)
 *   --courses=2,3,4 | shortname1,shortname2  (obligatorio: ids o shortnames)
 *   --owner=ID                            (opcional: ownerid del reporte; def: admin)
 *   --force                               (opcional: actualiza si ya existe)
 *   --wipe                                (opcional: borra TODOS los reportes
 *                                           propios del curso antes de importar)
 *   --addblock                            (opcional: agrega el bloque a la
 *                                           página del curso si falta)
 *
 * @package   block_configurable_reports
 * @copyright 2026 Awakelab
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// ---------------------------------------------------------------------------
// 1) Parseo de argumentos ANTES del bootstrap de Moodle.
// ---------------------------------------------------------------------------
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) {
        $cliargs[$m[1]] = $m[2];
    } else if (preg_match('/^--([^=]+)$/', $token, $m)) {
        $cliargs[$m[1]] = true;
    }
}

/**
 * Emite el resultado como JSON entre marcadores y termina.
 *
 * @param array $payload
 * @param int   $exitcode
 */
function cr_emit(array $payload, int $exitcode): void {
    fwrite(STDOUT, "<<<CR_RESULT>>>" . json_encode($payload, JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_string($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con la ruta a config.php', 'results' => []], 2);
}
if (!is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => "No se puede leer config.php: {$cliargs['config']}", 'results' => []], 2);
}
if (empty($cliargs['dir']) || !is_string($cliargs['dir']) || !is_dir($cliargs['dir'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --dir válido con los .xml de plantillas', 'results' => []], 2);
}
if (empty($cliargs['courses']) || !is_string($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses (ids o shortnames separados por coma)', 'results' => []], 2);
}

$templatesdir = rtrim($cliargs['dir'], '/');
$coursetokens = array_values(array_filter(array_map('trim', explode(',', $cliargs['courses'])), 'strlen'));
$force = !empty($cliargs['force']);
$wipe = !empty($cliargs['wipe']);
$addblock = !empty($cliargs['addblock']);
$owneroverride = isset($cliargs['owner']) && is_string($cliargs['owner']) && ctype_digit($cliargs['owner'])
    ? (int) $cliargs['owner']
    : null;

// ---------------------------------------------------------------------------
// 2) Bootstrap de Moodle (config.php deriva dirroot desde su propia ubicación).
// ---------------------------------------------------------------------------
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require($cliargs['config']);

global $CFG, $DB;

$locallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (!is_readable($locallib)) {
    cr_emit([
        'ok' => false,
        'fatal' => "No existe el plugin configurable_reports en {$CFG->dirroot}/blocks/configurable_reports",
        'results' => [],
    ], 3);
}
require_once($CFG->dirroot . '/lib/xmlize.php');
require_once($locallib);

// Owner por defecto: el administrador principal del sitio.
if ($owneroverride !== null) {
    $ownerid = $owneroverride;
} else {
    $admin = get_admin();
    $ownerid = $admin ? (int) $admin->id : 2;
}

/**
 * Resuelve un token (id numérico o shortname) a un registro de curso.
 *
 * @param string $token
 * @return object|false
 */
function cr_resolve_course(string $token) {
    global $DB;
    if (ctype_digit($token)) {
        return $DB->get_record('course', ['id' => (int) $token]);
    }
    return $DB->get_record('course', ['shortname' => $token]);
}

/**
 * Agrega la instancia del bloque a la página del curso si no existe ya
 * (equivalente a "Activar edición → Agregar un bloque" desde la interfaz).
 *
 * @param object $course
 * @param string $blockname
 * @return array ['status' => block_added|block_present|error, 'message' => ...]
 */
function cr_ensure_block(object $course, string $blockname): array {
    global $DB;

    $context = context_course::instance($course->id);
    $existing = $DB->get_record('block_instances', [
        'blockname' => $blockname,
        'parentcontextid' => $context->id,
    ]);
    if ($existing) {
        return ['status' => 'block_present', 'message' => "Bloque ya presente en el curso (id {$existing->id})"];
    }

    $instance = new stdClass();
    $instance->blockname = $blockname;
    $instance->parentcontextid = $context->id;
    $instance->showinsubcontexts = 0;
    $instance->pagetypepattern = 'course-view-*';
    $instance->subpagepattern = null;
    $instance->defaultregion = 'side-pre';
    $instance->defaultweight = 0;
    $instance->configdata = '';
    $instance->timecreated = time();
    $instance->timemodified = time();

    $instanceid = $DB->insert_record('block_instances', $instance);
    if (!$instanceid) {
        return ['status' => 'error', 'message' => 'No se pudo crear el registro en block_instances'];
    }

    // Asegura que exista el contexto de bloque: Moodle lo espera al renderizarlo.
    context_block::instance($instanceid);

    return ['status' => 'block_added', 'message' => "Bloque agregado a la página del curso (id {$instanceid})"];
}

/**
 * Importa un XML de plantilla a un curso reutilizando la lógica del plugin.
 * Devuelve ['status' => created|updated|skipped|error, 'report' => nombre, 'message' => ...].
 *
 * @param string $xmlcontent
 * @param object $course
 * @param int    $ownerid
 * @param bool   $force
 * @return array
 */
function cr_import_template(string $xmlcontent, object $course, int $ownerid, bool $force): array {
    global $DB;

    $data = xmlize($xmlcontent, 1, 'UTF-8');
    if (!isset($data['report']['@']['version']) || !isset($data['report']['#']) || !is_array($data['report']['#'])) {
        return ['status' => 'error', 'report' => '', 'message' => 'XML no es una exportación válida de Configurable Reports'];
    }

    $newreport = new stdClass();
    foreach ($data['report']['#'] as $key => $val) {
        if (!isset($val[0]['#'])) {
            continue;
        }
        if ($key === 'components') {
            // Misma transformación que cr_import_xml(): base64 -> unserialize -> (ajuste sql) -> serialize.
            $raw = trim((string) $val[0]['#']);
            $decoded = base64_decode($raw, true);
            if ($decoded === false) {
                return ['status' => 'error', 'report' => '', 'message' => 'El campo components tiene base64 inválido'];
            }
            $tempcomponents = cr_unserialize($decoded);
            if (!is_array($tempcomponents)) {
                return ['status' => 'error', 'report' => '', 'message' => 'El campo components no deserializó a un array válido'];
            }

            if (array_key_exists('customsql', $tempcomponents)) {
                $tempcomponents['customsql']['config']->courseid = $course->id;
                $querysql = str_replace(["\'", '\"'], ["'", '"'], $tempcomponents['customsql']['config']->querysql);
                $tempcomponents['customsql']['config']->querysql = $querysql;
            }

            $newreport->{$key} = trim(cr_serialize($tempcomponents));
        } else {
            $newreport->{$key} = trim((string) $val[0]['#']);
        }
    }

    if (empty($newreport->name)) {
        return ['status' => 'error', 'report' => '', 'message' => 'La plantilla no tiene <name>'];
    }
    if (empty($newreport->type)) {
        return ['status' => 'error', 'report' => $newreport->name, 'message' => 'La plantilla no tiene <type>'];
    }

    $newreport->courseid = $course->id;
    $newreport->ownerid = $ownerid;

    // Idempotencia: buscamos un reporte con el MISMO nombre en este curso.
    $existing = $DB->get_record('block_configurable_reports', ['courseid' => $course->id, 'name' => $newreport->name]);

    if ($existing) {
        if (!$force) {
            return ['status' => 'skipped', 'report' => $newreport->name, 'message' => 'Ya existe en el curso (usa --force para actualizar)'];
        }
        $newreport->id = $existing->id;
        $DB->update_record('block_configurable_reports', $newreport);
        return ['status' => 'updated', 'report' => $newreport->name, 'message' => "Actualizado (id {$existing->id})"];
    }

    $newid = $DB->insert_record('block_configurable_reports', $newreport);
    if (!$newid) {
        return ['status' => 'error', 'report' => $newreport->name, 'message' => 'insert_record devolvió falso'];
    }
    return ['status' => 'created', 'report' => $newreport->name, 'message' => "Creado (id {$newid})"];
}

// ---------------------------------------------------------------------------
// 3) Cargar las plantillas (.xml) de la carpeta y procesarlas por curso.
// ---------------------------------------------------------------------------
$xmlfiles = glob($templatesdir . '/*.xml');
sort($xmlfiles, SORT_NATURAL | SORT_FLAG_CASE);
if (!$xmlfiles) {
    cr_emit(['ok' => false, 'fatal' => "No se encontraron .xml en {$templatesdir}", 'results' => []], 4);
}

$results = [];
$snapshots = [];  // Snapshot de reportes previos por curso (para rollback).
$hadfatal = false;

foreach ($coursetokens as $token) {
    $course = cr_resolve_course($token);
    if (!$course) {
        $results[] = [
            'course' => $token,
            'coursename' => null,
            'file' => null,
            'report' => null,
            'status' => 'error',
            'message' => 'Curso no encontrado (id/shortname inexistente)',
        ];
        $hadfatal = true;
        continue;
    }

    if ($addblock) {
        try {
            $blockresult = cr_ensure_block($course, 'configurable_reports');
        } catch (Throwable $e) {
            $blockresult = ['status' => 'error', 'message' => $e->getMessage()];
        }
        $results[] = [
            'course' => (int) $course->id,
            'coursename' => $course->fullname,
            'file' => null,
            'report' => null,
            'status' => $blockresult['status'],
            'message' => $blockresult['message'],
        ];
    }

    // Snapshot de reportes existentes ANTES de tocar nada (para rollback).
    // Se exportan todos los campos de cada reporte del curso.
    // Se incluyen cursos sin reportes (reports=[]) para que el rollback
    // sepa que debe dejar el curso vacío si se restaura.
    $existingreports = $DB->get_records('block_configurable_reports', ['courseid' => $course->id], 'id ASC');
    $snapshotitems = [];
    if ($existingreports) {
        foreach ($existingreports as $rec) {
            $item = (array) $rec;
            unset($item['id']);  // El id se regenerará al restaurar.
            $snapshotitems[] = $item;
        }
    }
    $snapshots[] = [
        'courseid' => (int) $course->id,
        'coursename' => $course->fullname,
        'shortname' => $course->shortname ?? null,
        'report_count' => count($snapshotitems),
        'reports' => $snapshotitems,
    ];

    // Variable local: tras wipe se fuerza creación solo para ESTE curso,
    // sin afectar la variable global $force en cursos siguientes.
    $courseforce = $force;

    // Envolver wipe + importación en una transacción para que si falla a
    // mitad, el curso no quede sin reportes.
    $transaction = $DB->start_delegated_transaction();
    try {
        if ($wipe) {
            $existingcount = $DB->count_records('block_configurable_reports', ['courseid' => $course->id]);
            if ($existingcount > 0) {
                $DB->delete_records('block_configurable_reports', ['courseid' => $course->id]);
            }
            $results[] = [
                'course' => (int) $course->id,
                'coursename' => $course->fullname,
                'file' => null,
                'report' => null,
                'status' => 'wiped',
                'message' => "Eliminados {$existingcount} reporte(s) existente(s) antes de importar",
            ];
            // Tras vaciar no hay nada que "actualizar": fuerza la ruta de creación.
            $courseforce = true;
        }

        foreach ($xmlfiles as $file) {
            $xmlcontent = file_get_contents($file);
            if ($xmlcontent === false) {
                $results[] = [
                    'course' => (int) $course->id,
                    'coursename' => $course->fullname,
                    'file' => basename($file),
                    'report' => null,
                    'status' => 'error',
                    'message' => 'No se pudo leer el archivo',
                ];
                continue;
            }

            try {
                $r = cr_import_template($xmlcontent, $course, $ownerid, $courseforce);
            } catch (Throwable $e) {
                $r = ['status' => 'error', 'report' => null, 'message' => $e->getMessage()];
            }

            $results[] = [
                'course' => (int) $course->id,
                'coursename' => $course->fullname,
                'file' => basename($file),
                'report' => $r['report'],
                'status' => $r['status'],
                'message' => $r['message'],
            ];
        }

        $transaction->allow_commit();
    } catch (Throwable $e) {
        try {
            $transaction->rollback($e);
        } catch (Throwable $ignored) {
            // Moodle ya hizo rollback internamente.
        }
        $results[] = [
            'course' => (int) $course->id,
            'coursename' => $course->fullname,
            'file' => null,
            'report' => null,
            'status' => 'error',
            'message' => 'Transacción abortada: ' . $e->getMessage(),
        ];
        $hadfatal = true;
    }
}

cr_emit([
    'ok' => !$hadfatal,
    'owner' => $ownerid,
    'courses' => $coursetokens,
    'templates' => count($xmlfiles),
    'results' => $results,
    'snapshots' => $snapshots,
], 0);
