<?php
/**
 * Debug: verifica si los IDs del campo 'ids' de iTOP son cmids o instance_ids.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php debug_check_ids.php --config=/path/config.php --courseid=604\n");
define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$courseid = (int)($cliargs['courseid'] ?? 604);

// IDs que vimos en el debug de formdata
$test_ids = [21148, 21149, 21152, 21153, 21154, 52899, 52901, 52909, 52911];

$results = [];
foreach ($test_ids as $id) {
    $row = ['id' => $id];

    // Check as cmid in course_modules
    $cm = $DB->get_record('course_modules', ['id' => $id], 'id, course, module, instance');
    if ($cm) {
        $mod = $DB->get_record('modules', ['id' => $cm->module], 'name');
        $row['is_cmid'] = true;
        $row['cm_course'] = (int)$cm->course;
        $row['cm_module'] = $mod ? $mod->name : '?';
        $row['cm_instance'] = (int)$cm->instance;
        $row['in_course_604'] = ((int)$cm->course === $courseid);
    } else {
        $row['is_cmid'] = false;
    }

    // Check as instance_id for quiz
    $quiz_cm = $DB->get_record_sql(
        "SELECT cm.id AS cmid, cm.course FROM {course_modules} cm
         JOIN {modules} m ON m.id = cm.module
         WHERE m.name = 'quiz' AND cm.instance = ? AND cm.course = ?",
        [$id, $courseid]
    );
    if ($quiz_cm) {
        $row['is_quiz_instance'] = true;
        $row['quiz_cmid'] = (int)$quiz_cm->cmid;
    }

    // Check as instance_id for assign
    $assign_cm = $DB->get_record_sql(
        "SELECT cm.id AS cmid, cm.course FROM {course_modules} cm
         JOIN {modules} m ON m.id = cm.module
         WHERE m.name = 'assign' AND cm.instance = ? AND cm.course = ?",
        [$id, $courseid]
    );
    if ($assign_cm) {
        $row['is_assign_instance'] = true;
        $row['assign_cmid'] = (int)$assign_cm->cmid;
    }

    $results[] = $row;
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'checks' => $results], JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
