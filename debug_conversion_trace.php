<?php
/**
 * Trace exactly what convert_itop_selections_to_cmids does for each column.
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

// STAT_MODULE_MAP
$STAT_MAP = [
    'quiz_score' => 'quiz', 'quiz_completed_vs_total' => 'quiz',
    'quiz_completed_vs_total_moodle_criteria' => 'quiz',
    'quiz_average_score' => 'quiz', 'quiz_best_score' => 'quiz',
    'quiz_num_completed' => 'quiz',
    'assignment_num_completed_vs_total' => 'assign',
    'assignment_average_score' => 'assign', 'assignment_completed' => 'assign',
    'scorm_score' => 'scorm', 'scorm_completed_vs_total' => 'scorm',
    'scorm_average_score' => 'scorm',
    'course_modules_completed' => null,
];

$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
$field = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$itop_reports = $DB->get_records($rentedtable, [$field => $courseid], 'id ASC');

function decode_comp($raw) {
    if (empty($raw)) return null;
    if (function_exists('cr_unserialize')) return cr_unserialize($raw);
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    return @unserialize($raw2) ?: null;
}

$traces = [];

foreach ($itop_reports as $rep) {
    $comp = decode_comp($rep->components);
    if (!$comp) continue;
    $data = (array)$comp;
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $cols = is_object($cols) ? (array)$cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array)$elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array)$el : $el;
        if (strpos($el['pluginname'] ?? '', 'userstats') === false) continue;
        $fd = $el['formdata'] ?? $el;
        $fd = is_object($fd) ? (array)$fd : $fd;
        $ids_raw = $fd['ids'] ?? '';
        if (empty($ids_raw)) continue;

        $header = $fd['columname'] ?? '';
        $stat = $fd['stat'] ?? '';

        // Step 1: Parse ids with urldecode
        $decoded = urldecode($ids_raw);
        $ids = array_filter(array_map('intval', explode(',', $decoded)));
        $ids = array_values($ids);

        $trace = [
            'report' => $rep->name,
            'header' => $header,
            'stat' => $stat,
            'ids_raw' => $ids_raw,
            'ids_decoded' => $decoded,
            'ids_parsed' => $ids,
            'ids_count' => count($ids),
        ];

        // Step 2: Check which are valid in current course
        $valid = []; $invalid = [];
        foreach ($ids as $cid) {
            if ($DB->record_exists('course_modules', ['id' => $cid, 'course' => $courseid])) {
                $valid[] = $cid;
            } else {
                $invalid[] = $cid;
            }
        }
        $trace['valid_in_course'] = $valid;
        $trace['invalid_in_course'] = $invalid;

        // Step 3: If invalid, try ordinal mapping
        if (empty($valid) && !empty($invalid)) {
            $fallback_module = $STAT_MAP[$stat] ?? 'UNMAPPED';
            $trace['fallback_module'] = $fallback_module;

            if ($fallback_module !== null && $fallback_module !== 'UNMAPPED') {
                $mod = $DB->get_record('modules', ['name' => $fallback_module], 'id');
                $trace['module_record'] = $mod ? (int)$mod->id : null;

                if ($mod) {
                    // Find source course
                    $source_courseid = 0;
                    foreach ($invalid as $v) {
                        $src_cm = $DB->get_record('course_modules', ['id' => $v], 'course, module');
                        if ($src_cm) {
                            $source_courseid = (int)$src_cm->course;
                            $trace['source_cm_module_id'] = (int)$src_cm->module;
                            $trace['source_cm_is_same_module'] = ((int)$src_cm->module === (int)$mod->id);
                            break;
                        }
                    }
                    $trace['source_course'] = $source_courseid;

                    // All cmids of this module in SOURCE course
                    $src_cms = $DB->get_records('course_modules', [
                        'course' => $source_courseid, 'module' => $mod->id
                    ], 'section ASC, id ASC', 'id');
                    $source_all = array_map('intval', array_keys($src_cms));
                    $trace['source_all_cmids'] = $source_all;

                    // Find positions
                    $positions = [];
                    foreach ($ids as $v) {
                        $pos = array_search((int)$v, $source_all);
                        $positions[] = ['cmid' => $v, 'position' => $pos];
                    }
                    $trace['position_mapping'] = $positions;

                    // Dest cmids
                    $dest_cms = $DB->get_records('course_modules', [
                        'course' => $courseid, 'module' => $mod->id
                    ], 'section ASC, id ASC', 'id');
                    $dest_all = array_map('intval', array_keys($dest_cms));
                    $trace['dest_all_cmids'] = $dest_all;

                    // Map
                    $selected_positions = [];
                    foreach ($positions as $p) {
                        if ($p['position'] !== false) $selected_positions[] = $p['position'];
                    }
                    $mapped = [];
                    foreach ($selected_positions as $pos) {
                        if (isset($dest_all[$pos])) $mapped[] = $dest_all[$pos];
                    }
                    $trace['selected_positions'] = $selected_positions;
                    $trace['mapped_cmids'] = $mapped;

                    // What if cmids are NOT of this module type?
                    // Check actual module type of the stored cmids
                    foreach ($ids as $v) {
                        $cm_info = $DB->get_record('course_modules', ['id' => $v], 'id, course, module, instance');
                        if ($cm_info) {
                            $mod_name = $DB->get_field('modules', 'name', ['id' => $cm_info->module]);
                            $trace['cmid_details'][] = [
                                'cmid' => $v,
                                'course' => (int)$cm_info->course,
                                'module_id' => (int)$cm_info->module,
                                'module_name' => $mod_name,
                                'instance' => (int)$cm_info->instance,
                            ];
                        }
                    }
                }
            }
        }

        $traces[] = $trace;
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'traces' => $traces],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
