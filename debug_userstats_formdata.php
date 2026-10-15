<?php
/**
 * Debug: dump formdata of userstats columns from iTOP reports in a course.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
    else if (preg_match('/^--([^=]+)$/', $token, $m)) $cliargs[$m[1]] = true;
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    die("Usage: php debug_userstats_formdata.php --config=/path/config.php --courseid=604\n");
}

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);

// Find iTOP table
$dbman = $DB->get_manager();
$rentedtable = '';
foreach (['block_advanced_reports'] as $t) {
    if ($dbman->table_exists($t)) { $rentedtable = $t; break; }
}

if (empty($rentedtable)) die("No iTOP table found\n");

// Get iTOP reports for this course
$reports = $DB->get_records($rentedtable, ['courseid' => $courseid], 'id ASC');

$result = [];

foreach ($reports as $rep) {
    $raw = $rep->components;
    if (empty($raw)) continue;

    // Deserialize preserving types
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $data = @unserialize($raw2);
    if ($data === false) continue;
    if (function_exists('urldecode_recursive')) $data = urldecode_recursive($data);
    $data = (array) $data;

    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $cols = is_object($cols) ? (array) $cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array) $elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array) $el : $el;
        $pluginname = $el['pluginname'] ?? '';
        if ($pluginname !== 'userstats') continue;

        $fd = $el['formdata'] ?? null;
        if (!$fd) continue;
        $fd = is_object($fd) ? (array) $fd : $fd;

        $result[] = [
            'report_id' => (int)$rep->id,
            'report_name' => $rep->name ?? '',
            'col_index' => $idx,
            'columname' => $fd['columname'] ?? $fd['column_name'] ?? '',
            'formdata_keys' => array_keys($fd),
            'formdata_full' => $fd,
        ];
    }
}

$flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'columns' => $result], $flags) . "<<<END_CR_RESULT>>>\n";
