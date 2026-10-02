<?php
/**
 * Auditoría de coherencia del Informe Global.
 *
 * Compara valores del bridge/cache iTOP con cálculos propios desde logstore/DB
 * para detectar discrepancias en todos los campos del Informe Global.
 *
 * Uso: php auditoria_informe_global.php [max_courses] [sample_users]
 *   max_courses   = número máximo de cursos a auditar (default 50)
 *   sample_users  = usuarios muestra por curso (default 5)
 *
 * Salida: JSON envuelto en <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/enrollib.php');

global $DB;

$maxcourses  = isset($argv[1]) ? (int)$argv[1] : 50;
$sampleusers = isset($argv[2]) ? (int)$argv[2] : 5;

// ------------------------------------------------------------------
// 1. Find courses that have iTOP cached data (bridge-enabled courses)
// ------------------------------------------------------------------
$cachetable = 'block_adv_reports_values';
$tableexists = false;
try {
    $tableexists = $DB->get_manager()->table_exists($cachetable);
} catch (Throwable $t) {
    // table doesn't exist
}

if (!$tableexists) {
    $result = ['error' => 'Table block_adv_reports_values does not exist. No bridge data available.'];
    echo "<<<CR_RESULT>>>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
    exit(0);
}

// Get distinct courseids with cached data
$sql = "SELECT DISTINCT courseid FROM {{$cachetable}} ORDER BY courseid";
$courseids_with_cache = $DB->get_fieldset_sql($sql);

if (empty($courseids_with_cache)) {
    $result = ['error' => 'No courses with cached iTOP data found.'];
    echo "<<<CR_RESULT>>>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
    exit(0);
}

// Limit to max_courses
$courseids_to_audit = array_slice($courseids_with_cache, 0, $maxcourses);

// ------------------------------------------------------------------
// 2. Helper functions for own calculations (replicate plugin logic)
// ------------------------------------------------------------------

/**
 * Get filtered logstore WHERE clause for time tracking (courseonly scope).
 */
function audit_get_logs_where(int $userid, int $courseid, array &$params): string {
    global $DB;
    $params = [
        'userid' => $userid,
        'courseid' => $courseid,
        'ttcontextcourse' => CONTEXT_COURSE,
        'ttcontextmodule' => CONTEXT_MODULE,
        'tttargetcourse' => 'course',
        'ttactionviewedcourse' => 'viewed',
        'ttactionviewedmodule' => 'viewed',
        'ttcrudread' => 'r',
        'ttcrudcreate' => 'c',
        'ttcrudupdate' => 'u',
        'ttmodcomponentlike' => 'mod\_%',
    ];

    $where = "l.userid = :userid AND l.courseid = :courseid AND (
        (l.contextlevel = :ttcontextcourse AND l.target = :tttargetcourse AND l.action = :ttactionviewedcourse)
        OR
        (l.contextlevel = :ttcontextmodule AND " .
            $DB->sql_like('l.component', ':ttmodcomponentlike', false, false) .
        " AND (l.action = :ttactionviewedmodule OR l.crud = :ttcrudread OR l.crud = :ttcrudcreate OR l.crud = :ttcrudupdate))
    )";

    return $where;
}

/**
 * Count total logs (registros).
 */
function audit_count_logs(int $userid, int $courseid): int {
    global $DB;
    $params = [];
    $where = audit_get_logs_where($userid, $courseid, $params);
    return (int)$DB->get_field_sql("SELECT COUNT(1) FROM {logstore_standard_log} l WHERE $where", $params);
}

/**
 * Count distinct connection days.
 */
function audit_count_days(int $userid, int $courseid): int {
    global $DB;
    $params = [];
    $where = audit_get_logs_where($userid, $courseid, $params);
    return (int)$DB->get_field_sql("SELECT COUNT(DISTINCT FLOOR(l.timecreated / 86400)) FROM {logstore_standard_log} l WHERE $where", $params);
}

/**
 * Calculate total connection seconds using session-gap method.
 * Returns [total_seconds, daily_totals_array, daily_count]
 */
function audit_calc_connection_time(int $userid, int $courseid, int $sessionlimit = 14400): array {
    global $DB;
    $params = [];
    $where = audit_get_logs_where($userid, $courseid, $params);

    $sql = "SELECT l.id, l.timecreated, l.eventname, FLOOR(l.timecreated / 86400) AS daybucket
              FROM {logstore_standard_log} l
             WHERE $where
           ORDER BY l.timecreated ASC, l.id ASC";
    $logs = $DB->get_records_sql($sql, $params);

    if (!$logs) {
        return [0, [], 0];
    }

    $totalsbyday = [];
    $prevbucket = null;
    $prevtime = null;

    foreach ($logs as $log) {
        $daybucket = (int)$log->daybucket;
        $currenttime = (int)$log->timecreated;
        $islogin = ($log->eventname === '\\core\\event\\user_loggedin');

        if (!array_key_exists($daybucket, $totalsbyday)) {
            $totalsbyday[$daybucket] = 0;
        }

        if ($prevbucket !== null && $daybucket === $prevbucket && $prevtime !== null) {
            $delta = $currenttime - $prevtime;
            if ($delta > 0 && $delta <= $sessionlimit && !$islogin) {
                $totalsbyday[$daybucket] += $delta;
            }
        }

        $prevbucket = $daybucket;
        $prevtime = $currenttime;
    }

    // Apply max daily hours cap
    $maxdailyhours = (int)get_config('block_configurable_reports', 'max_daily_hours_' . $courseid);
    $maxdailyseconds = ($maxdailyhours > 0) ? $maxdailyhours * 3600 : 0;

    $total = 0;
    foreach ($totalsbyday as $secs) {
        $dayval = max(0, (int)$secs);
        if ($maxdailyseconds > 0) {
            $dayval = min($dayval, $maxdailyseconds);
        }
        $total += $dayval;
    }

    return [$total, $totalsbyday, count($totalsbyday)];
}

/**
 * Format seconds as "XXh YYm ZZs".
 */
function audit_format_hms(int $seconds): string {
    $seconds = max(0, $seconds);
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds % 3600) / 60);
    $s = (int)($seconds % 60);
    return sprintf('%02dh %02dm %02ds', $h, $m, $s);
}

/**
 * Get first access from logstore.
 */
function audit_first_access(int $userid, int $courseid): ?int {
    global $DB;
    $params = [];
    $where = audit_get_logs_where($userid, $courseid, $params);
    $val = $DB->get_field_sql("SELECT MIN(l.timecreated) FROM {logstore_standard_log} l WHERE $where", $params);
    return ($val !== false && $val !== null) ? (int)$val : null;
}

/**
 * Get last access from logstore.
 */
function audit_last_access(int $userid, int $courseid): ?int {
    global $DB;
    $params = [];
    $where = audit_get_logs_where($userid, $courseid, $params);
    $val = $DB->get_field_sql("SELECT MAX(l.timecreated) FROM {logstore_standard_log} l WHERE $where", $params);
    return ($val !== false && $val !== null) ? (int)$val : null;
}

/**
 * Count correos (mail messages).
 */
function audit_count_correos(int $userid, int $courseid): int {
    global $DB;
    // Count messages sent by this user to enrolled users in this course
    $sql = "SELECT COUNT(m.id)
              FROM {messages} m
              JOIN {message_conversations} mc ON mc.id = m.conversationid
              JOIN {message_conversation_members} mcm ON mcm.conversationid = mc.id AND mcm.userid != m.useridfrom
             WHERE m.useridfrom = :userid
               AND mcm.userid IN (
                   SELECT ue.userid FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                   WHERE e.courseid = :courseid
               )";
    $count = $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    return ($count !== false) ? (int)$count : 0;
}

/**
 * Count forum posts.
 */
function audit_count_forum_posts(int $userid, int $courseid): int {
    global $DB;
    $sql = "SELECT COUNT(1)
              FROM {forum_posts} fp
              JOIN {forum_discussions} fd ON fd.id = fp.discussion
              JOIN {forum} f ON f.id = fd.forum
             WHERE fp.userid = :userid AND f.course = :courseid";
    $count = $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    return ($count !== false) ? (int)$count : 0;
}

/**
 * Count messages with students (chat).
 */
function audit_count_messages_students(int $userid, int $courseid): int {
    global $DB;
    // Get student role users in this course
    $studentroles = $DB->get_records('role', ['archetype' => 'student'], '', 'id');
    if (empty($studentroles)) return 0;

    $roleids = array_keys($studentroles);
    list($insql, $inparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

    $sql = "SELECT DISTINCT ra.userid
              FROM {role_assignments} ra
              JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :ctxlevel
             WHERE ctx.instanceid = :courseid AND ra.roleid $insql";
    $sparams = array_merge(['ctxlevel' => CONTEXT_COURSE, 'courseid' => $courseid], $inparams);
    $students = $DB->get_fieldset_sql($sql, $sparams);

    if (empty($students)) return 0;

    list($insql2, $inparams2) = $DB->get_in_or_equal($students, SQL_PARAMS_NAMED, 'stu');
    $msql = "SELECT COUNT(m.id)
               FROM {messages} m
               JOIN {message_conversation_members} mcm ON mcm.conversationid = m.conversationid
                    AND mcm.userid != m.useridfrom
              WHERE m.useridfrom = :userid AND mcm.userid $insql2";
    $mparams = array_merge(['userid' => $userid], $inparams2);
    $count = $DB->get_field_sql($msql, $mparams);
    return ($count !== false) ? (int)$count : 0;
}

/**
 * Get nota_final.
 */
function audit_nota_final(int $userid, int $courseid): string {
    global $DB;
    $sql = "SELECT gg.finalgrade FROM {grade_items} gi
            LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
            WHERE gi.courseid = :courseid AND gi.itemtype = 'course'
            ORDER BY gi.id ASC";
    $grade = $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid], IGNORE_MISSING);
    if ($grade === false || $grade === null || $grade === '') return '0.00';
    return number_format((float)$grade, 2);
}

/**
 * Get bridge cached value.
 */
function audit_get_bridge_value(int $courseid, int $userid, string $itopstat): ?string {
    global $DB;
    $sql = "SELECT value FROM {block_adv_reports_values}
            WHERE courseid = :courseid AND userid = :userid AND stat = :stat
            ORDER BY id DESC";
    $val = $DB->get_field_sql($sql, [
        'courseid' => $courseid,
        'userid' => $userid,
        'stat' => $itopstat,
    ], IGNORE_MISSING);
    if ($val === false || $val === null) return null;
    $clean = trim(strip_tags($val));
    return ($clean !== '') ? $clean : null;
}

// STAT_MAP from bridge.php
$STAT_MAP = [
    'tiempo_total'           => ['coursededicationtime2', 'platformdedicationtime'],
    'actividades_aprendizaje' => ['assignment_num_completed_vs_total'],
    'contenidos_visualizados' => ['course_modules_completed', 'course_modules_visited'],
    'evaluaciones'           => ['quiz_completed_vs_total_moodle_criteria'],
    'dias_conexion'          => ['distinct_days_connection'],
    'primer_acceso'          => ['first_connection'],
    'ultimo_acceso'          => ['last_connection'],
    'mensajes_foro'          => ['interactions_with_forums'],
    'correos'                => ['teacher_num_messages_with_students'],
    'mensajes_alumnos'       => ['total_messages', 'num_messages_in_chats'],
];

function get_bridge_for_stat(int $courseid, int $userid, string $stat_type, array $statmap): ?string {
    if (!isset($statmap[$stat_type])) return null;
    foreach ($statmap[$stat_type] as $itopstat) {
        $val = audit_get_bridge_value($courseid, $userid, $itopstat);
        if ($val !== null && $val !== '') return $val;
    }
    return null;
}

// ------------------------------------------------------------------
// 3. Run the audit
// ------------------------------------------------------------------
$results = [
    'timestamp' => date('Y-m-d H:i:s'),
    'server' => gethostname(),
    'max_courses' => $maxcourses,
    'sample_users' => $sampleusers,
    'total_courses_with_cache' => count($courseids_with_cache),
    'courses_audited' => 0,
    'total_users_audited' => 0,
    'total_discrepancies' => 0,
    'summary_by_stat' => [],
    'courses' => [],
];

// Track discrepancy counts per stat
$statdiscrepancies = [];
$statcounts = [];

foreach ($courseids_to_audit as $courseid) {
    // Check course exists
    $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname', IGNORE_MISSING);
    if (!$course) continue;

    // Get enrolled users with student role (sample)
    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) continue;

    $enrolledusers = get_enrolled_users($context, '', 0, 'u.id,u.username,u.firstname,u.lastname', 'u.lastname ASC', 0, 0);
    if (empty($enrolledusers)) continue;

    // Filter to students with cache data
    $usersWithData = [];
    foreach ($enrolledusers as $user) {
        $hasdata = $DB->record_exists('block_adv_reports_values', ['courseid' => $courseid, 'userid' => $user->id]);
        if ($hasdata) {
            $usersWithData[] = $user;
        }
        if (count($usersWithData) >= $sampleusers) break;
    }
    if (empty($usersWithData)) continue;

    $courseresult = [
        'courseid' => $courseid,
        'shortname' => $course->shortname,
        'fullname' => $course->fullname,
        'users' => [],
    ];

    foreach ($usersWithData as $user) {
        $userid = (int)$user->id;
        $userresult = [
            'userid' => $userid,
            'username' => $user->username,
            'fullname' => $user->firstname . ' ' . $user->lastname,
            'comparisons' => [],
            'discrepancies' => [],
        ];

        // --- tiempo_total ---
        $bridgetiempo = get_bridge_for_stat($courseid, $userid, 'tiempo_total', $STAT_MAP);
        list($ownseconds, $dailytotals, $dailycount) = audit_calc_connection_time($userid, $courseid);
        $ownformatted = audit_format_hms($ownseconds);
        $sumDaily = array_sum($dailytotals);

        $userresult['comparisons']['tiempo_total'] = [
            'bridge' => $bridgetiempo,
            'own_calc_seconds' => $ownseconds,
            'own_calc_formatted' => $ownformatted,
            'sum_daily_seconds' => $sumDaily,
            'sum_daily_formatted' => audit_format_hms($sumDaily),
            'daily_days_count' => $dailycount,
            'match_own_vs_daily' => ($ownseconds === $sumDaily),
        ];
        if (!isset($statcounts['tiempo_total'])) { $statcounts['tiempo_total'] = 0; $statdiscrepancies['tiempo_total'] = 0; }
        $statcounts['tiempo_total']++;
        if ($bridgetiempo !== null && $bridgetiempo !== $ownformatted) {
            $userresult['discrepancies'][] = "tiempo_total: bridge='{$bridgetiempo}' vs own='{$ownformatted}'";
            $statdiscrepancies['tiempo_total']++;
        }

        // --- dias_conexion ---
        $bridgedias = get_bridge_for_stat($courseid, $userid, 'dias_conexion', $STAT_MAP);
        $owndays = audit_count_days($userid, $courseid);
        $userresult['comparisons']['dias_conexion'] = [
            'bridge' => $bridgedias,
            'own_calc' => $owndays,
        ];
        if (!isset($statcounts['dias_conexion'])) { $statcounts['dias_conexion'] = 0; $statdiscrepancies['dias_conexion'] = 0; }
        $statcounts['dias_conexion']++;
        if ($bridgedias !== null && (string)$owndays !== $bridgedias) {
            $userresult['discrepancies'][] = "dias_conexion: bridge='{$bridgedias}' vs own='{$owndays}'";
            $statdiscrepancies['dias_conexion']++;
        }

        // --- primer_acceso ---
        $bridgeprimer = get_bridge_for_stat($courseid, $userid, 'primer_acceso', $STAT_MAP);
        $ownfirst = audit_first_access($userid, $courseid);
        $ownfirstfmt = ($ownfirst !== null) ? userdate($ownfirst, '%d/%m/%Y %H:%M') : 'No visitado';
        $userresult['comparisons']['primer_acceso'] = [
            'bridge' => $bridgeprimer,
            'own_calc' => $ownfirstfmt,
            'own_timestamp' => $ownfirst,
        ];
        if (!isset($statcounts['primer_acceso'])) { $statcounts['primer_acceso'] = 0; $statdiscrepancies['primer_acceso'] = 0; }
        $statcounts['primer_acceso']++;
        // Compare dates loosely (bridge may have different format)
        if ($bridgeprimer !== null && strpos($bridgeprimer, 'No') === false && $ownfirst !== null) {
            // Extract just date from bridge for comparison
            $bridgedate = preg_replace('/[^\d\/]/', '', substr($bridgeprimer, 0, 10));
            $owndate = date('d/m/Y', $ownfirst);
            if ($bridgedate !== $owndate && trim($bridgeprimer) !== trim($ownfirstfmt)) {
                $userresult['discrepancies'][] = "primer_acceso: bridge='{$bridgeprimer}' vs own='{$ownfirstfmt}'";
                $statdiscrepancies['primer_acceso']++;
            }
        }

        // --- ultimo_acceso ---
        $bridgeultimo = get_bridge_for_stat($courseid, $userid, 'ultimo_acceso', $STAT_MAP);
        $ownlast = audit_last_access($userid, $courseid);
        $ownlastfmt = ($ownlast !== null) ? userdate($ownlast, '%d/%m/%Y %H:%M') : 'No visitado';
        $userresult['comparisons']['ultimo_acceso'] = [
            'bridge' => $bridgeultimo,
            'own_calc' => $ownlastfmt,
            'own_timestamp' => $ownlast,
        ];
        if (!isset($statcounts['ultimo_acceso'])) { $statcounts['ultimo_acceso'] = 0; $statdiscrepancies['ultimo_acceso'] = 0; }
        $statcounts['ultimo_acceso']++;
        if ($bridgeultimo !== null && strpos($bridgeultimo, 'No') === false && $ownlast !== null) {
            $bridgedate2 = preg_replace('/[^\d\/]/', '', substr($bridgeultimo, 0, 10));
            $owndate2 = date('d/m/Y', $ownlast);
            if ($bridgedate2 !== $owndate2 && trim($bridgeultimo) !== trim($ownlastfmt)) {
                $userresult['discrepancies'][] = "ultimo_acceso: bridge='{$bridgeultimo}' vs own='{$ownlastfmt}'";
                $statdiscrepancies['ultimo_acceso']++;
            }
        }

        // --- correos ---
        $bridgecorreos = get_bridge_for_stat($courseid, $userid, 'correos', $STAT_MAP);
        $owncorreos = audit_count_correos($userid, $courseid);
        $userresult['comparisons']['correos'] = [
            'bridge' => $bridgecorreos,
            'own_calc' => $owncorreos,
        ];
        if (!isset($statcounts['correos'])) { $statcounts['correos'] = 0; $statdiscrepancies['correos'] = 0; }
        $statcounts['correos']++;
        if ($bridgecorreos !== null && (string)$owncorreos !== $bridgecorreos) {
            $userresult['discrepancies'][] = "correos: bridge='{$bridgecorreos}' vs own='{$owncorreos}'";
            $statdiscrepancies['correos']++;
        }

        // --- mensajes_foro ---
        $bridgeforo = get_bridge_for_stat($courseid, $userid, 'mensajes_foro', $STAT_MAP);
        $ownforo = audit_count_forum_posts($userid, $courseid);
        $userresult['comparisons']['mensajes_foro'] = [
            'bridge' => $bridgeforo,
            'own_calc' => $ownforo,
        ];
        if (!isset($statcounts['mensajes_foro'])) { $statcounts['mensajes_foro'] = 0; $statdiscrepancies['mensajes_foro'] = 0; }
        $statcounts['mensajes_foro']++;
        if ($bridgeforo !== null && (string)$ownforo !== $bridgeforo) {
            $userresult['discrepancies'][] = "mensajes_foro: bridge='{$bridgeforo}' vs own='{$ownforo}'";
            $statdiscrepancies['mensajes_foro']++;
        }

        // --- mensajes_alumnos ---
        $bridgealumnos = get_bridge_for_stat($courseid, $userid, 'mensajes_alumnos', $STAT_MAP);
        $ownalumnos = audit_count_messages_students($userid, $courseid);
        $userresult['comparisons']['mensajes_alumnos'] = [
            'bridge' => $bridgealumnos,
            'own_calc' => $ownalumnos,
        ];
        if (!isset($statcounts['mensajes_alumnos'])) { $statcounts['mensajes_alumnos'] = 0; $statdiscrepancies['mensajes_alumnos'] = 0; }
        $statcounts['mensajes_alumnos']++;
        if ($bridgealumnos !== null && (string)$ownalumnos !== $bridgealumnos) {
            $userresult['discrepancies'][] = "mensajes_alumnos: bridge='{$bridgealumnos}' vs own='{$ownalumnos}'";
            $statdiscrepancies['mensajes_alumnos']++;
        }

        // --- nota_final (siempre cálculo propio, no bridge) ---
        $ownnota = audit_nota_final($userid, $courseid);
        $userresult['comparisons']['nota_final'] = [
            'source' => 'own_calc_only',
            'value' => $ownnota,
        ];

        // --- evaluaciones (bridge vs DB) ---
        $bridgeeval = get_bridge_for_stat($courseid, $userid, 'evaluaciones', $STAT_MAP);
        $userresult['comparisons']['evaluaciones'] = [
            'bridge' => $bridgeeval,
            'note' => 'Complex format - manual review recommended',
        ];

        // --- actividades_aprendizaje ---
        $bridgeact = get_bridge_for_stat($courseid, $userid, 'actividades_aprendizaje', $STAT_MAP);
        $userresult['comparisons']['actividades_aprendizaje'] = [
            'bridge' => $bridgeact,
            'note' => 'Complex format - manual review recommended',
        ];

        // --- contenidos_visualizados ---
        $bridgecontenidos = get_bridge_for_stat($courseid, $userid, 'contenidos_visualizados', $STAT_MAP);
        $userresult['comparisons']['contenidos_visualizados'] = [
            'bridge' => $bridgecontenidos,
            'note' => 'Complex format - manual review recommended',
        ];

        $disccount = count($userresult['discrepancies']);
        $results['total_discrepancies'] += $disccount;

        $courseresult['users'][] = $userresult;
        $results['total_users_audited']++;
    }

    $results['courses'][] = $courseresult;
    $results['courses_audited']++;

    // Progress indicator to stderr
    fprintf(STDERR, "Audited course %d (%s) - %d users\n", $courseid, $course->shortname, count($usersWithData));
}

// Build summary
foreach ($statcounts as $stat => $count) {
    $disc = $statdiscrepancies[$stat] ?? 0;
    $results['summary_by_stat'][$stat] = [
        'total_compared' => $count,
        'discrepancies' => $disc,
        'match_rate' => ($count > 0) ? round(100 * ($count - $disc) / $count, 1) . '%' : 'N/A',
    ];
}

echo "<<<CR_RESULT>>>" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
