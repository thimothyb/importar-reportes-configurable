<?php
/**
 * Debug: dump formdata of userstatsadvanced columns from OUR plugin.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php debug_own_formdata.php --config=/path/config.php --courseid=604\n");
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);

$reports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
$result = [];

foreach ($reports as $rep) {
    $raw = $rep->components;
    if (empty($raw)) continue;

    // Use cr_unserialize if available, else raw
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
        $pluginname = $el['pluginname'] ?? '';
        if ($pluginname !== 'userstatsadvanced') continue;

        $fd = $el['formdata'] ?? null;
        if (!$fd) continue;
        $fd_arr = is_object($fd) ? (array) $fd : $fd;

        // Check if this column has any selection-related fields
        $has_selections = false;
        foreach (['actividades', 'activities', 'cmids', 'ids', 'selected'] as $sf) {
            if (!empty($fd_arr[$sf])) { $has_selections = true; break; }
        }

        $result[] = [
            'report_id' => (int)$rep->id,
            'report_name' => $rep->name ?? '',
            'col_index' => $idx,
            'columname' => $fd_arr['columname'] ?? $fd_arr['column_name'] ?? '',
            'stat_type' => $fd_arr['stat_type'] ?? $fd_arr['stat'] ?? '',
            'has_selections' => $has_selections,
            'formdata_keys' => array_keys($fd_arr),
            'formdata_full' => $fd_arr,
            'formdata_is_object' => is_object($fd),
        ];
    }
}

$flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'columns' => $result], $flags) . "<<<END_CR_RESULT>>>\n";
