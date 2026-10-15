<?php
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
$field = $DB->get_manager()->field_exists('block_configurable_reports', 'courseid') ? 'courseid' : 'course';
$reports = $DB->get_records('block_configurable_reports', [$field => $courseid], 'id ASC');
$results = [];
foreach ($reports as $rep) {
    $comp = function_exists('cr_unserialize') ? cr_unserialize((string)$rep->components) : null;
    if (!$comp) continue;
    $info = ['id' => (int)$rep->id, 'name' => $rep->name, 'top' => gettype($comp)];
    $cols = is_array($comp) ? ($comp['columns'] ?? null) : (is_object($comp) ? ($comp->columns ?? null) : null);
    $info['columns'] = gettype($cols);
    if ($cols) {
        $els = is_array($cols) ? ($cols['elements'] ?? null) : (is_object($cols) ? ($cols->elements ?? null) : null);
        $info['elements'] = gettype($els);
        if ($els) {
            $earr = is_object($els) ? (array)$els : $els;
            foreach ($earr as $i => $e) {
                $pn = is_object($e) ? ($e->pluginname ?? '') : ($e['pluginname'] ?? '');
                $et = gettype($e);
                $fd = is_object($e) ? ($e->formdata ?? null) : ($e['formdata'] ?? null);
                $ft = gettype($fd);
                $info['cols'][] = ['i' => $i, 'el_type' => $et, 'fd_type' => $ft, 'plugin' => $pn];
            }
        }
    }
    $results[] = $info;
}
echo "<<<CR_RESULT>>>" . json_encode(['ok'=>true,'results'=>$results], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
