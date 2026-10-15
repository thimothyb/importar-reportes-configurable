<?php
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php debug_selectedcmids.php --config=/path/config.php --courseid=604\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);
$reports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
$result = [];

foreach ($reports as $rep) {
    $raw = $rep->components;
    if (empty($raw)) continue;
    if (function_exists('cr_unserialize')) {
        $data = cr_unserialize($raw);
    } else {
        $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
        $data = @unserialize($raw2);
        if ($data === false) continue;
    }
    $data = (array) $data;
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $cols = is_object($cols) ? (array) $cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array) $elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array) $el : $el;
        if (($el['pluginname'] ?? '') !== 'userstatsadvanced') continue;
        $fd = $el['formdata'] ?? null;
        if (!$fd) continue;
        $fd_arr = is_object($fd) ? (array) $fd : $fd;
        
        // Solo columnas que tengan actividades o selectedcmids
        $act = $fd_arr['actividades'] ?? null;
        $sel = $fd_arr['selectedcmids'] ?? null;
        if ($act === null && $sel === null) continue;

        $result[] = [
            'report' => $rep->name,
            'col_index' => $idx,
            'columname' => $fd_arr['columname'] ?? '',
            'stat' => $fd_arr['stat_type'] ?? $fd_arr['stat'] ?? '',
            'actividades' => $act,
            'selectedcmids' => $sel,
            'selectedcmids_type' => gettype($sel),
        ];
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'cols' => $result], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
