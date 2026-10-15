<?php
/**
 * Diagnóstico: muestra TODAS las columnas de iTOP y las nuestras para un curso,
 * indicando cuáles tienen selections (ids) y cómo se emparejan los headers.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courseid=604\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);

function decode_comp($raw) {
    if (empty($raw)) return null;
    if (function_exists('cr_unserialize')) return cr_unserialize($raw);
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    return @unserialize($raw2) ?: null;
}
function deep_to_array($v) {
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) return array_map('deep_to_array', $v);
    return $v;
}
function normalize_header($h) {
    $h = mb_strtolower(trim($h));
    $h = preg_replace('/\s+/', ' ', $h);
    return $h;
}

// iTOP reports
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
$rfield = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$itop_reports = $DB->get_records($rentedtable, [$rfield => $courseid], 'id ASC');

// Own reports
$own_table = 'block_configurable_reports';
$ofield = $DB->get_manager()->field_exists($own_table, 'courseid') ? 'courseid' : 'course';
$own_reports = $DB->get_records($own_table, [$ofield => $courseid], 'id ASC');

$result = ['itop_reports' => [], 'own_reports' => []];

foreach ($itop_reports as $rep) {
    $comp = decode_comp($rep->components);
    if (!$comp) continue;
    $data = deep_to_array((array)$comp);
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $elements = (is_array($cols) ? $cols : (array)$cols)['elements'] ?? [];

    $report_info = ['name' => $rep->name, 'id' => (int)$rep->id, 'columns' => []];
    foreach ($elements as $idx => $el) {
        $fd = $el['formdata'] ?? $el;
        $header = $fd['columname'] ?? ($fd['column'] ?? '');
        $plugin = $el['pluginname'] ?? '';
        $has_ids = !empty($fd['ids'] ?? '');
        $ids_raw = $fd['ids'] ?? '';
        $stat = $fd['stat'] ?? '';

        $report_info['columns'][] = [
            'idx' => $idx,
            'header' => $header,
            'header_norm' => normalize_header($header),
            'pluginname' => $plugin,
            'has_ids' => $has_ids,
            'ids_raw' => $has_ids ? (string)$ids_raw : '',
            'stat' => $stat,
        ];
    }
    $result['itop_reports'][] = $report_info;
}

foreach ($own_reports as $rep) {
    $comp = decode_comp($rep->components);
    if (!$comp) continue;
    $data = deep_to_array((array)$comp);
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $elements = (is_array($cols) ? $cols : (array)$cols)['elements'] ?? [];

    $report_info = ['name' => $rep->name, 'id' => (int)$rep->id, 'columns' => []];
    foreach ($elements as $idx => $el) {
        $fd = $el['formdata'] ?? $el;
        $header = $fd['columname'] ?? ($fd['column'] ?? '');
        $plugin = $el['pluginname'] ?? '';

        $report_info['columns'][] = [
            'idx' => $idx,
            'header' => $header,
            'header_norm' => normalize_header($header),
            'pluginname' => $plugin,
        ];
    }
    $result['own_reports'][] = $report_info;
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'data' => $result],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
