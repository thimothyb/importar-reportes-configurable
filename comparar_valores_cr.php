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
 * CLI de COMPARATIVA: valores iTOP (block_adv_reports_values) vs.
 * valores devueltos por el bridge legacy de Configurable Reports.
 *
 * Para cada curso que tiene datos iTOP, recorre todos los usuarios
 * matriculados y compara lo que devuelve el bridge con lo que está
 * almacenado en la tabla de cache del iTOP.
 *
 * No modifica nada: es de solo lectura.
 *
 * Parámetros:
 *   --config=/ruta/a/moodle/config.php   (obligatorio)
 *   --courseids=123,456                   (opcional, limitar a cursos específicos)
 *   --limit=10                            (opcional, max cursos a procesar)
 *
 * Salida: JSON entre marcadores <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
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

function cr_emit(array $payload, int $exitcode): void {
    fwrite(STDOUT, "<<<CR_RESULT>>>" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config=/ruta/a/moodle/config.php'], 1);
}

$configfile = $cliargs['config'];
if (!is_file($configfile)) {
    cr_emit(['ok' => false, 'fatal' => "config.php no encontrado: $configfile"], 1);
}

// ---------------------------------------------------------------------------
// 2) Bootstrap de Moodle.
// ---------------------------------------------------------------------------
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require(dirname($configfile) . '/config.php');

global $DB;

// ---------------------------------------------------------------------------
// 3) Verificar que las tablas y clases necesarias existen.
// ---------------------------------------------------------------------------
$dbman = $DB->get_manager();

if (!$dbman->table_exists('block_adv_reports_values')) {
    cr_emit(['ok' => false, 'fatal' => 'La tabla block_adv_reports_values no existe. No hay datos iTOP para comparar.'], 1);
}

// Verificar que el plugin configurable_reports y el bridge están disponibles.
$bridgefile = $CFG->dirroot . '/blocks/configurable_reports/classes/legacy/bridge.php';
if (!file_exists($bridgefile)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró el bridge legacy del plugin configurable_reports.'], 1);
}

require_once($bridgefile);
use block_configurable_reports\legacy\bridge;

// ---------------------------------------------------------------------------
// 4) Determinar cursos a comparar.
// ---------------------------------------------------------------------------
$filtercourseids = [];
if (!empty($cliargs['courseids'])) {
    $filtercourseids = array_map('intval', explode(',', $cliargs['courseids']));
}

$limit = !empty($cliargs['limit']) ? (int)$cliargs['limit'] : 0;

// Si no se especificaron cursos, obtener todos los que tienen datos en values.
if (empty($filtercourseids)) {
    $sql = "SELECT DISTINCT courseid FROM {block_adv_reports_values} ORDER BY courseid ASC";
    $filtercourseids = $DB->get_fieldset_sql($sql);
}

if ($limit > 0) {
    $filtercourseids = array_slice($filtercourseids, 0, $limit);
}

if (empty($filtercourseids)) {
    cr_emit([
        'ok' => true,
        'message' => 'No hay cursos con datos iTOP para comparar.',
        'courses' => [],
        'summary' => ['total_courses' => 0, 'total_users' => 0, 'total_comparisons' => 0, 'total_matches' => 0, 'total_diffs' => 0],
    ], 0);
}

// ---------------------------------------------------------------------------
// 5) El STAT_MAP del bridge — lo leemos directamente de la clase.
// ---------------------------------------------------------------------------
$statmap = bridge::get_stat_map();

// Invertir: para cada itop_stat, saber qué stat_types lo usan.
$itop_to_stattypes = [];
foreach ($statmap as $stattype => $itopstats) {
    foreach ($itopstats as $itopstat) {
        $itop_to_stattypes[$itopstat][] = $stattype;
    }
}

// ---------------------------------------------------------------------------
// 6) Recorrer cursos y comparar.
// ---------------------------------------------------------------------------
$allresults = [];
$summarymatches = 0;
$summarydiffs = 0;
$summarycomparisons = 0;
$summaryusers = 0;

foreach ($filtercourseids as $courseid) {
    $courseid = (int)$courseid;

    // Verificar que el curso existe.
    $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname');
    if (!$course) {
        continue;
    }

    // Obtener todos los valores iTOP para este curso.
    $itoprecords = $DB->get_records('block_adv_reports_values', ['courseid' => $courseid]);
    if (empty($itoprecords)) {
        continue;
    }

    // Agrupar por userid → stat → value (primer reportid gana, igual que el bridge).
    $itopdata = [];
    // Ordenar por reportid ASC para que el primero gane.
    usort($itoprecords, function($a, $b) { return ($a->reportid ?? 0) <=> ($b->reportid ?? 0); });
    foreach ($itoprecords as $rec) {
        $uid = (int)$rec->userid;
        $stat = $rec->stat;
        if (!isset($itopdata[$uid][$stat]) && $rec->value !== null && $rec->value !== '') {
            $itopdata[$uid][$stat] = $rec->value;
        }
    }

    // Precargar datos en el bridge para este curso.
    $userids = array_keys($itopdata);
    bridge::preload($courseid, $userids);

    $coursediffs = [];
    $coursematches = 0;
    $coursecomparisons = 0;

    foreach ($itopdata as $userid => $userstats) {
        // Para cada stat iTOP del usuario, ver si el bridge devuelve lo mismo.
        foreach ($userstats as $itopstat => $itopvalue) {
            // ¿Qué stat_types mapean a este itop_stat?
            if (!isset($itop_to_stattypes[$itopstat])) {
                // Este stat de iTOP no está mapeado en el bridge — informar.
                $coursediffs[] = [
                    'userid' => $userid,
                    'itop_stat' => $itopstat,
                    'itop_value' => $itopvalue,
                    'stat_type' => '(sin mapeo)',
                    'bridge_value' => null,
                    'match' => false,
                    'reason' => 'No hay mapping en el bridge para este stat iTOP',
                ];
                $coursecomparisons++;
                $summarydiffs++;
                continue;
            }

            // Usar el primer stat_type que mapea a este itop_stat.
            $primarystattype = $itop_to_stattypes[$itopstat][0];

            // Obtener lo que devuelve el bridge.
            $bridgevalue = bridge::resolve($courseid, $userid, $primarystattype);

            // El bridge hace strip_tags y trim, así que comparemos igual.
            $cleanitop = trim(strip_tags($itopvalue));
            if ($cleanitop === '') {
                $cleanitop = $itopvalue;
            }

            $coursecomparisons++;
            $summarycomparisons++;

            if ($bridgevalue === $cleanitop) {
                $coursematches++;
                $summarymatches++;
            } else {
                $coursediffs[] = [
                    'userid' => $userid,
                    'itop_stat' => $itopstat,
                    'itop_value' => $itopvalue,
                    'itop_clean' => $cleanitop,
                    'stat_type' => $primarystattype,
                    'bridge_value' => $bridgevalue,
                    'match' => false,
                ];
                $summarydiffs++;
            }
        }
    }

    $summaryusers += count($itopdata);

    $courseresult = [
        'courseid' => $courseid,
        'shortname' => $course->shortname,
        'fullname' => $course->fullname,
        'users_compared' => count($itopdata),
        'total_comparisons' => $coursecomparisons,
        'matches' => $coursematches,
        'diffs_count' => count($coursediffs),
        'match_percent' => $coursecomparisons > 0 ? round(($coursematches / $coursecomparisons) * 100, 2) : 100,
    ];

    // Solo incluir detalle de diffs si hay (para no inflar el JSON).
    if (!empty($coursediffs)) {
        // Limitar a 50 diffs por curso para no explotar el JSON.
        $courseresult['diffs'] = array_slice($coursediffs, 0, 50);
        if (count($coursediffs) > 50) {
            $courseresult['diffs_truncated'] = true;
            $courseresult['diffs_total'] = count($coursediffs);
        }
    }

    $allresults[] = $courseresult;

    // Reset bridge cache para el siguiente curso.
    bridge::reset_cache();
}

// ---------------------------------------------------------------------------
// 7) Emitir resultado.
// ---------------------------------------------------------------------------
cr_emit([
    'ok' => true,
    'summary' => [
        'total_courses' => count($allresults),
        'total_users' => $summaryusers,
        'total_comparisons' => $summarycomparisons,
        'total_matches' => $summarymatches,
        'total_diffs' => $summarydiffs,
        'match_percent' => $summarycomparisons > 0 ? round(($summarymatches / $summarycomparisons) * 100, 2) : 100,
    ],
    'courses' => $allresults,
], 0);
