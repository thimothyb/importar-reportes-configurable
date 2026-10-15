<?php
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path/config.php --courseid=604\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $DB;

$courseid = (int)($cliargs['courseid'] ?? 604);
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
if (!$rentedtable) { echo json_encode(['error' => 'no rented table']); exit; }

$field = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$reports = $DB->get_records($rentedtable, [$field => $courseid], 'id ASC');

function decode_components($raw) {
    if (empty($raw)) return null;
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $d = @unserialize($raw2);
    return $d !== false ? $d : null;
}

$result = [];
foreach ($reports as $rep) {
    $comp = decode_components($rep->components);
    if (!$comp) continue;
    $data = (array)$comp;
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $cols = is_object($cols) ? (array)$cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array)$elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array)$el : $el;
        $pn = $el['pluginname'] ?? '';
        if (strpos($pn, 'userstats') === false) continue;
        $fd = $el['formdata'] ?? $el;
        $fd = is_object($fd) ? (array)$fd : $fd;
        $ids_raw = $fd['ids'] ?? '';
        $ids = [];
        if (!empty($ids_raw)) {
            if (is_string($ids_raw)) $ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $ids_raw)));
            else if (is_array($ids_raw)) $ids = array_map('intval', $ids_raw);
        }
        $result[] = [
            'report' => $rep->name,
            'header' => $fd['columname'] ?? $fd['column_name'] ?? '',
            'stat' => $fd['stat'] ?? $fd['stat_type'] ?? '',
            'ids' => array_values($ids),
            'ids_count' => count($ids),
            'pluginname' => $pn,
        ];
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'columns' => $result],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
