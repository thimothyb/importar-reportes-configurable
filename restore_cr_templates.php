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
 * CLI para RESTAURAR reportes de "Configurable Reports" desde un snapshot
 * JSON generado por import_cr_templates.php.
 *
 * Flujo:
 *   1. Lee el JSON de snapshot (generado antes del wipe/importación).
 *   2. Por cada curso en el snapshot:
 *      a. Borra TODOS los reportes propios actuales del curso.
 *      b. Inserta los reportes del snapshot tal cual estaban.
 *   3. Todo envuelto en una transacción por curso.
 *
 * Este script se sube por SFTP a /tmp y se ejecuta por SSH como el usuario
 * web (p.ej. www-data), igual que import_cr_templates.php.
 *
 * Salida: JSON entre marcadores <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
 *
 * Parámetros:
 *   --config=/ruta/a/moodle/config.php   (obligatorio)
 *   --snapshot=/tmp/cr_snapshot_xxx.json  (obligatorio: archivo de snapshot)
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
if (empty($cliargs['snapshot']) || !is_string($cliargs['snapshot'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --snapshot con la ruta al JSON de snapshot', 'results' => []], 2);
}
if (!is_readable($cliargs['snapshot'])) {
    cr_emit(['ok' => false, 'fatal' => "No se puede leer el snapshot: {$cliargs['snapshot']}", 'results' => []], 2);
}

$snapshotraw = file_get_contents($cliargs['snapshot']);
if ($snapshotraw === false) {
    cr_emit(['ok' => false, 'fatal' => "No se pudo leer el archivo de snapshot", 'results' => []], 2);
}
$snapshotdata = json_decode($snapshotraw, true);
if (!is_array($snapshotdata) || empty($snapshotdata['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'El snapshot no tiene la estructura esperada (falta clave "courses")', 'results' => []], 2);
}

// ---------------------------------------------------------------------------
// 2) Bootstrap de Moodle.
// ---------------------------------------------------------------------------
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require($cliargs['config']);

global $CFG, $DB;

// Verificar que la tabla existe.
if (!$DB->get_manager()->table_exists('block_configurable_reports')) {
    cr_emit([
        'ok' => false,
        'fatal' => 'La tabla block_configurable_reports no existe. ¿Está instalado el plugin?',
        'results' => [],
    ], 3);
}

// ---------------------------------------------------------------------------
// 3) Restaurar por curso.
// ---------------------------------------------------------------------------
$results = [];
$hadfatal = false;

foreach ($snapshotdata['courses'] as $entry) {
    $courseid = (int) ($entry['courseid'] ?? 0);
    $coursename = $entry['coursename'] ?? "curso {$courseid}";
    $reports = $entry['reports'] ?? [];

    if (!$courseid) {
        $results[] = [
            'courseid' => 0,
            'coursename' => $coursename,
            'status' => 'error',
            'message' => 'Entrada de snapshot sin courseid válido',
        ];
        $hadfatal = true;
        continue;
    }

    // Verificar que el curso existe.
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname', IGNORE_MISSING);
    if (!$course) {
        $results[] = [
            'courseid' => $courseid,
            'coursename' => $coursename,
            'status' => 'error',
            'message' => "Curso id={$courseid} no encontrado en la plataforma",
        ];
        $hadfatal = true;
        continue;
    }

    $transaction = $DB->start_delegated_transaction();
    try {
        // Borrar reportes actuales del curso.
        $currentcount = $DB->count_records('block_configurable_reports', ['courseid' => $courseid]);
        $DB->delete_records('block_configurable_reports', ['courseid' => $courseid]);

        // Restaurar cada reporte del snapshot.
        $restoredcount = 0;
        foreach ($reports as $reportdata) {
            $record = (object) $reportdata;
            // Asegurar que el courseid es correcto (por si el snapshot se usa
            // en otra plataforma con los mismos ids).
            $record->courseid = $courseid;
            // Eliminar id si viene (se genera nuevo).
            unset($record->id);
            $DB->insert_record('block_configurable_reports', $record);
            $restoredcount++;
        }

        $transaction->allow_commit();

        $results[] = [
            'courseid' => $courseid,
            'coursename' => $course->fullname,
            'status' => 'restored',
            'removed' => $currentcount,
            'restored' => $restoredcount,
            'message' => "Eliminados {$currentcount} reporte(s) actuales, restaurados {$restoredcount} del snapshot",
        ];
    } catch (Throwable $e) {
        try {
            $transaction->rollback($e);
        } catch (Throwable $ignored) {
            // Moodle ya hizo rollback internamente.
        }
        $results[] = [
            'courseid' => $courseid,
            'coursename' => $course->fullname,
            'status' => 'error',
            'message' => 'Transacción abortada: ' . $e->getMessage(),
        ];
        $hadfatal = true;
    }
}

cr_emit([
    'ok' => !$hadfatal,
    'server' => $snapshotdata['server'] ?? null,
    'snapshot_date' => $snapshotdata['snapshot_date'] ?? null,
    'results' => $results,
], 0);
