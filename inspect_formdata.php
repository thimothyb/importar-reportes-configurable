<?php
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --reportid=10037\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;
$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$reportid = (int)($cliargs['reportid'] ?? 10037);
$rep = $DB->get_record('block_configurable_reports', ['id' => $reportid]);
if (!$rep) die("Report $reportid not found\n");

$comp = cr_unserialize((string)$rep->components);
$cols = $comp['columns'] ?? null;
$elements = $cols['elements'] ?? [];

$results = [];
foreach ($elements as $i => $el) {
    $pn = $el['pluginname'] ?? '';
    if (strpos($pn, 'userstats') === false) continue;
    $fd = $el['formdata'] ?? null;
    if (!$fd) continue;
    $fd_arr = is_object($fd) ? (array)$fd : $fd;
    // Show all keys and their values/types
    $fields = [];
    foreach ($fd_arr as $k => $v) {
        $fields[$k] = ['type' => gettype($v), 'value' => is_array($v) ? json_encode($v) : (string)$v];
    }
    $results[] = ['index' => $i, 'plugin' => $pn, 'header' => $el['summary'] ?? '', 'formdata_keys' => $fields];
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'report' => $reportid, 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
