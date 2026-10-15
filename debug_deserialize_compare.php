<?php
/**
 * Diagnóstico: compara EXACTAMENTE lo que produce cr_unserialize vs unserialize
 * para los componentes iTOP, y muestra las keys del formdata de columnas userstats.
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

// iTOP table
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
$rfield = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$itop_reports = $DB->get_records($rentedtable, [$rfield => $courseid], 'id ASC');

$results = [];

foreach ($itop_reports as $rep) {
    $raw = $rep->components;

    // Method 1: cr_unserialize (what safe_deserialize calls first)
    $cr_result = function_exists('cr_unserialize') ? cr_unserialize($raw) : null;
    // Method 2: plain unserialize with object fix
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $plain_result = @unserialize($raw2) ?: null;

    // Get columns from cr_unserialize result
    $cr_cols = [];
    if ($cr_result) {
        $comp = is_object($cr_result) ? (array)$cr_result : $cr_result;
        $cols = $comp['columns'] ?? $comp['Columns'] ?? null;
        if ($cols) {
            $cols = is_object($cols) ? (array)$cols : $cols;
            $elements = $cols['elements'] ?? [];
            if (is_object($elements)) $elements = (array)$elements;
            foreach ($elements as $idx => $el) {
                $el = is_object($el) ? (array)$el : $el;
                $pname = $el['pluginname'] ?? '';
                if (strpos($pname, 'userstats') !== false) {
                    $fd = $el['formdata'] ?? $el;
                    $fd = is_object($fd) ? (array)$fd : $fd;
                    // Deep convert
                    array_walk_recursive($fd, function(&$v) { if (is_object($v)) $v = (array)$v; });
                    $cr_cols[] = [
                        'idx' => $idx,
                        'pluginname' => $pname,
                        'header' => $fd['columname'] ?? '',
                        'formdata_keys' => array_keys($fd),
                        'has_ids_key' => array_key_exists('ids', $fd),
                        'ids_value' => isset($fd['ids']) ? (is_string($fd['ids']) ? substr($fd['ids'],0,100) : gettype($fd['ids'])) : 'NOT_SET',
                        'ids_empty' => empty($fd['ids'] ?? null),
                        'stat' => $fd['stat'] ?? '',
                    ];
                }
            }
        }
    }

    // Get columns from plain unserialize result
    $plain_cols = [];
    if ($plain_result) {
        $comp = is_object($plain_result) ? (array)$plain_result : $plain_result;
        $cols = $comp['columns'] ?? $comp['Columns'] ?? null;
        if ($cols) {
            $cols = is_object($cols) ? (array)$cols : $cols;
            $elements = $cols['elements'] ?? [];
            if (is_object($elements)) $elements = (array)$elements;
            foreach ($elements as $idx => $el) {
                $el = is_object($el) ? (array)$el : $el;
                $pname = $el['pluginname'] ?? '';
                if (strpos($pname, 'userstats') !== false) {
                    $fd = $el['formdata'] ?? $el;
                    $fd = is_object($fd) ? (array)$fd : $fd;
                    array_walk_recursive($fd, function(&$v) { if (is_object($v)) $v = (array)$v; });
                    $plain_cols[] = [
                        'idx' => $idx,
                        'pluginname' => $pname,
                        'header' => $fd['columname'] ?? '',
                        'formdata_keys' => array_keys($fd),
                        'has_ids_key' => array_key_exists('ids', $fd),
                        'ids_value' => isset($fd['ids']) ? (is_string($fd['ids']) ? substr($fd['ids'],0,100) : gettype($fd['ids'])) : 'NOT_SET',
                        'ids_empty' => empty($fd['ids'] ?? null),
                        'stat' => $fd['stat'] ?? '',
                    ];
                }
            }
        }
    }

    if (!empty($cr_cols) || !empty($plain_cols)) {
        $results[] = [
            'report_name' => $rep->name,
            'report_id' => (int)$rep->id,
            'cr_unserialize_ok' => $cr_result !== null && $cr_result !== false,
            'plain_unserialize_ok' => $plain_result !== null,
            'cr_unserialize_type' => gettype($cr_result),
            'plain_unserialize_type' => gettype($plain_result),
            'cr_cols' => $cr_cols,
            'plain_cols' => $plain_cols,
            'raw_first_100' => substr($raw, 0, 100),
        ];
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'cr_unserialize_exists' => function_exists('cr_unserialize'), 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
