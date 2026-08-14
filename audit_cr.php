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
 * CLI de AUDITORÍA para "Informes Avanzados" (alquilado) vs. "Configurable
 * Reports" (propio).
 *
 * Son DOS plugins distintos (component y tablas diferentes):
 *   - Alquilado: block_advanced_reports  (blockname: advanced_reports)
 *   - Propio:    block_configurable_reports (blockname: configurable_reports)
 *
 * No compartimos tablas con el alquilado (es de código cerrado/ofuscado), así
 * que de él solo se puede detectar de forma fiable y segura:
 *   - si el plugin está presente en disco (carpeta blocks/advanced_reports),
 *   - la versión registrada en BD (config_plugins), y
 *   - en qué cursos tiene el bloque añadido a la página (block_instances +
 *     context), que es la señal de "informe activo" en ese curso.
 * NO se lee su version.php ni se consulta ninguna tabla propia suya (podría
 * estar ofuscada/cifrada); esto es intencional para no arriesgar nada.
 *
 * Del plugin propio sí se informa todo: versión en BD y en ficheros (pueden
 * diferir si se subió código nuevo pero falta correr upgrade.php), si el
 * bloque está añadido, y los reportes ya guardados en
 * block_configurable_reports (útil para confirmar cursos ya migrados).
 *
 * `needs_migration` en cada curso = el bloque alquilado está añadido ahí.
 * Esos son los courseids que instalar_plantillas_cr.py (modo migración)
 * tomará como objetivo.
 *
 * No modifica nada: es de solo lectura.
 *
 * Este script se sube por SFTP a /tmp y se ejecuta por SSH como el usuario
 * web (p.ej. www-data), igual que import_cr_templates.php.
 *
 * Salida: imprime un bloque JSON entre marcadores para que el orquestador
 * Python lo parsee de forma fiable (ignorando cualquier ruido de stdout):
 *
 *   <<<CR_RESULT>>>{...json...}<<<END_CR_RESULT>>>
 *
 * Parámetros (se parsean ANTES de arrancar Moodle, no usan clilib):
 *   --config=/ruta/a/moodle/config.php   (obligatorio)
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
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con la ruta a config.php'], 2);
}
if (!is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => "No se puede leer config.php: {$cliargs['config']}"], 2);
}

// ---------------------------------------------------------------------------
// 2) Bootstrap de Moodle (config.php deriva dirroot desde su propia ubicación).
// ---------------------------------------------------------------------------
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require($cliargs['config']);

global $CFG, $DB;

/**
 * Cuenta cursos con un blockname dado, añadido a la página del curso.
 * Devuelve [courseid => nº de instancias] usando CONTEXT_COURSE (=50).
 *
 * @param string $blockname
 * @return array<int,int>
 */
function cr_block_courseids(string $blockname): array {
    global $DB;
    $sql = "SELECT bi.id AS instanceid, ctx.instanceid AS courseid
              FROM {block_instances} bi
              JOIN {context} ctx ON ctx.id = bi.parentcontextid
             WHERE bi.blockname = :blockname
               AND ctx.contextlevel = :ctxlevel";
    $rows = $DB->get_records_sql($sql, ['blockname' => $blockname, 'ctxlevel' => CONTEXT_COURSE]);
    $result = [];
    foreach ($rows as $row) {
        $courseid = (int) $row->courseid;
        $result[$courseid] = ($result[$courseid] ?? 0) + 1;
    }
    return $result;
}

// ---------------------------------------------------------------------------
// 3) Metadatos de AMBOS plugins.
// ---------------------------------------------------------------------------
$rentedcomponent = 'block_advanced_reports';
$rentedpath = $CFG->dirroot . '/blocks/advanced_reports';
$rented = [
    'component'      => $rentedcomponent,
    'blockname'      => 'advanced_reports',
    'plugin_present' => is_dir($rentedpath),
    'db_version'     => $DB->get_field('config_plugins', 'value', ['plugin' => $rentedcomponent, 'name' => 'version']),
];

$owncomponent = 'block_configurable_reports';
$ownpath = $CFG->dirroot . '/blocks/configurable_reports';
$own = [
    'component'      => $owncomponent,
    'blockname'      => 'configurable_reports',
    'plugin_present' => is_dir($ownpath),
    'db_version'     => null,
    'files_version'  => null,
    'files_release'  => null,
];
if ($own['plugin_present']) {
    $own['db_version'] = $DB->get_field('config_plugins', 'value', ['plugin' => $owncomponent, 'name' => 'version']);
    $versionfile = $ownpath . '/version.php';
    if (is_readable($versionfile)) {
        // Se incluye en una función para no contaminar el scope global.
        $meta = (function () use ($versionfile) {
            $plugin = new stdClass();
            include($versionfile);
            return $plugin;
        })();
        $own['files_version'] = $meta->version ?? null;
        $own['files_release'] = $meta->release ?? null;
    }
}

// ---------------------------------------------------------------------------
// 4) Cursos con cada bloque añadido a la página del curso.
// ---------------------------------------------------------------------------
$rentedcourseids = cr_block_courseids('advanced_reports');
$owncourseids = cr_block_courseids('configurable_reports');

// ---------------------------------------------------------------------------
// 5) Reportes propios existentes (block_configurable_reports), por curso.
//    (Del alquilado NO se consulta ninguna tabla: es código cerrado y no
//    sabemos su esquema; solo usamos la señal de block_instances.)
// ---------------------------------------------------------------------------
$reportsbycourse = [];
if ($own['plugin_present'] || $DB->get_manager()->table_exists('block_configurable_reports')) {
    $reportrows = $DB->get_records('block_configurable_reports', null, 'courseid ASC, id ASC', 'id, courseid, name, type');
    foreach ($reportrows as $row) {
        $courseid = (int) $row->courseid;
        $reportsbycourse[$courseid][] = [
            'id'   => (int) $row->id,
            'name' => $row->name,
            'type' => $row->type,
        ];
    }
}

// ---------------------------------------------------------------------------
// 6) Unión de cursos afectados por cualquiera de los dos bloques.
// ---------------------------------------------------------------------------
$allcourseids = array_unique(array_merge(
    array_keys($rentedcourseids),
    array_keys($owncourseids),
    array_keys($reportsbycourse)
));
sort($allcourseids, SORT_NUMERIC);

$courses = [];
foreach ($allcourseids as $courseid) {
    $courserec = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname', IGNORE_MISSING);
    $reports = $reportsbycourse[$courseid] ?? [];
    $rentedadded = isset($rentedcourseids[$courseid]);
    $courses[] = [
        'courseid'              => $courseid,
        'fullname'              => $courserec ? $courserec->fullname : null,
        'shortname'             => $courserec ? $courserec->shortname : null,
        'course_missing'        => !$courserec,
        'rented_block_added'    => $rentedadded,
        'rented_block_instances' => $rentedcourseids[$courseid] ?? 0,
        'own_block_added'       => isset($owncourseids[$courseid]),
        'own_block_instances'   => $owncourseids[$courseid] ?? 0,
        'own_report_count'      => count($reports),
        'own_reports'           => $reports,
        'needs_migration'       => $rentedadded,
    ];
}

cr_emit([
    'ok'        => true,
    'installed' => [
        'rented' => $rented,
        'own'    => $own,
    ],
    'courses'   => $courses,
], 0);
