<?php
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path/config.php --courseid=604\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);

// Find rented table
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}

// Get iTOP reports for this course
$itop_reports = [];
if ($rentedtable) {
    $field = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
    $itop_reports = $DB->get_records($rentedtable, [$field => $courseid], 'id ASC');
}

// Get our reports
$own_reports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');

function decode_components($raw) {
    if (empty($raw)) return null;
    if (function_exists('cr_unserialize')) return cr_unserialize($raw);
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $d = @unserialize($raw2);
    return $d !== false ? $d : null;
}

function get_userstats_cols($components) {
    $data = (array) $components;
    $cols = $data['columns'] ?? null;
    if (!$cols) return [];
    $cols = is_object($cols) ? (array)$cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array)$elements;
    $result = [];
    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array)$el : $el;
        $pn = $el['pluginname'] ?? '';
        if (strpos($pn, 'userstats') === false) continue;
        $fd = $el['formdata'] ?? $el;
        $fd = is_object($fd) ? (array)$fd : $fd;
        $result[] = ['index' => $idx, 'formdata' => $fd, 'pluginname' => $pn,
                     'header' => $fd['columname'] ?? $fd['column_name'] ?? ''];
    }
    return $result;
}

$comparisons = [];

foreach ($own_reports as $own_rep) {
    $own_comp = decode_components($own_rep->components);
    if (!$own_comp) continue;
    $own_cols = get_userstats_cols($own_comp);
    if (empty($own_cols)) continue;

    // Match iTOP report by name prefix
    $itop_rep = null;
    $own_prefix = substr($own_rep->name, 0, 3);
    foreach ($itop_reports as $ir) {
        if (substr($ir->name, 0, 3) === $own_prefix) { $itop_rep = $ir; break; }
    }

    foreach ($own_cols as $oc) {
        $fd = $oc['formdata'];
        $act = $fd['actividades'] ?? null;
        $sel = $fd['selectedcmids'] ?? null;
        if ($act === null && $sel === null) continue;

        // Find matching iTOP column
        $itop_ids = null;
        $itop_header = '';
        if ($itop_rep) {
            $itop_comp = decode_components($itop_rep->components);
            if ($itop_comp) {
                $itop_cols = get_userstats_cols($itop_comp);
                foreach ($itop_cols as $ic) {
                    $ic_fd = $ic['formdata'];
                    $ic_h = $ic_fd['columname'] ?? $ic_fd['column_name'] ?? '';
                    if (strtolower(trim($ic_h)) === strtolower(trim($oc['header']))) {
                        $itop_header = $ic_h;
                        // Get ids from iTOP
                        $itop_ids_raw = $ic_fd['ids'] ?? '';
                        if (!empty($itop_ids_raw)) {
                            if (is_string($itop_ids_raw)) {
                                $itop_ids = array_filter(array_map('intval', preg_split('/[\s,;]+/', $itop_ids_raw)));
                            } else if (is_array($itop_ids_raw)) {
                                $itop_ids = array_map('intval', $itop_ids_raw);
                            }
                        }
                        break;
                    }
                }
            }
        }

        $comparisons[] = [
            'report' => $own_rep->name,
            'column' => $oc['header'],
            'stat' => $fd['stat_type'] ?? $fd['stat'] ?? '',
            'our_actividades_count' => is_array($act) ? count($act) : 'N/A',
            'our_actividades' => $act,
            'our_selectedcmids' => $sel,
            'itop_ids' => $itop_ids,
            'itop_ids_count' => $itop_ids !== null ? count($itop_ids) : 'no_match',
        ];
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'comparisons' => $comparisons],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
