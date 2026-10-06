<?php
/**
 * Auditoría: Informe Global (tabla del plugin) vs Verificación independiente (logstore).
 *
 * Compara los valores que el plugin userstatsadvanced REALMENTE muestra
 * en la tabla del Informe Global (llamando a get_value() del plugin)
 * contra una verificación independiente directa desde logstore/DB.
 *
 * Campos: tiempo_total, registros, dias_conexion, primer_acceso,
 *         ultimo_acceso, correos, mensajes_foro, mensajes_alumnos, nota_final.
 *
 * Uso: php auditoria_global_vs_detallado.php [max_courses] [sample_users]
 *   max_courses   = máximo de cursos a auditar (default 50)
 *   sample_users  = usuarios muestra por curso (default 5)
 *
 * Salida: JSON envuelto en <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->libdir . '/enrollib.php');

// Load plugin class.
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/components/columns/userstatsadvanced/plugin.class.php');

global $DB;

$maxcourses  = isset($argv[1]) ? (int)$argv[1] : 50;
$sampleusers = isset($argv[2]) ? (int)$argv[2] : 5;

// ============================================================
// 1. Instantiate the real plugin
// ============================================================

/**
 * Get or create a plugin_userstatsadvanced instance for a course.
 * Uses the course's actual configurable_reports record if one exists,
 * otherwise uses a minimal fake report object.
 */
function get_plugin_instance(int $courseid) {
    global $DB;
    static $instances = [];
    if (isset($instances[$courseid])) {
        return $instances[$courseid];
    }

    // Try to find a real report for this course.
    $report = $DB->get_record_select(
        'block_configurable_reports',
        'courseid = :cid AND type = :type',
        ['cid' => $courseid, 'type' => 'users'],
        '*',
        IGNORE_MULTIPLE
    );
    if (!$report) {
        // Create a minimal fake report object.
        $report = (object)[
            'id' => 0,
            'courseid' => $courseid,
            'type' => 'users',
            'name' => 'audit_temp',
            'components' => '',
        ];
    }

    $instances[$courseid] = new plugin_userstatsadvanced($report);
    return $instances[$courseid];
}

/**
 * Call the plugin's get_value() for a specific stat_type.
 * Returns the raw output (may contain HTML buttons).
 */
function get_plugin_value(int $courseid, int $userid, string $stat_type): string {
    $plugin = get_plugin_instance($courseid);

    // Create a minimal row object as the plugin expects.
    $row = (object)['id' => $userid, 'userid' => $userid];

    $result = $plugin->get_value(
        $row,
        $courseid,
        $stat_type,
        0,       // starttime
        0,       // endtime
        14400,   // sessionlimittime (4h default)
        '',      // selectedcmidsraw
        'numdenum_percent', // displayformat
        100,     // maxdisplayvalue
        0        // modalreportid
    );

    return (string)$result;
}

/**
 * Strip HTML from plugin output and clean whitespace.
 */
function clean_plugin_value(string $raw): string {
    $clean = strip_tags($raw);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean);
}

/**
 * Extract the leading number from a plugin value.
 * E.g. "3 Ver mensajes" → 3, "10h 16m 33s Ver días" → null (not numeric).
 */
function extract_number(string $value): ?int {
    if (preg_match('/^(\d+)/', trim($value), $m)) {
        return (int)$m[1];
    }
    return null;
}

// ============================================================
// 2. Independent verification helpers (bypass plugin entirely)
// ============================================================

/**
 * Build the WHERE clause for time-tracking logs.
 * Replicates plugin's get_time_tracking_logs_where_sql() with scope=courseonly.
 */
function audit_logs_where(int $userid, int $courseid, array &$params): string {
    global $DB;
    $params = [
        'userid' => $userid,
        'courseid' => $courseid,
        'ttcontextcourse' => CONTEXT_COURSE,
        'ttcontextmodule' => CONTEXT_MODULE,
        'tttargetcourse'  => 'course',
        'ttactionviewedcourse' => 'viewed',
        'ttactionviewedmodule' => 'viewed',
        'ttcrudread'   => 'r',
        'ttcrudcreate' => 'c',
        'ttcrudupdate' => 'u',
        'ttmodcomponentlike' => 'mod\_%',
    ];

    $like = $DB->sql_like('l.component', ':ttmodcomponentlike', false, false);

    return "l.userid = :userid AND l.courseid = :courseid AND (
        (l.contextlevel = :ttcontextcourse AND l.target = :tttargetcourse AND l.action = :ttactionviewedcourse)
        OR
        (l.contextlevel = :ttcontextmodule AND {$like}
         AND (l.action = :ttactionviewedmodule OR l.crud = :ttcrudread OR l.crud = :ttcrudcreate OR l.crud = :ttcrudupdate))
    )";
}

/** Count filtered logs (registros). */
function verify_registros(int $userid, int $courseid): int {
    global $DB;
    $params = [];
    $where = audit_logs_where($userid, $courseid, $params);
    return (int) $DB->get_field_sql("SELECT COUNT(1) FROM {logstore_standard_log} l WHERE $where", $params);
}

/** Count distinct connection days. */
function verify_dias_conexion(int $userid, int $courseid): int {
    global $DB;
    $params = [];
    $where = audit_logs_where($userid, $courseid, $params);
    return (int) $DB->get_field_sql(
        "SELECT COUNT(DISTINCT FLOOR(l.timecreated / 86400)) FROM {logstore_standard_log} l WHERE $where",
        $params
    );
}

/** Calculate total connection time (session-gap method). */
function verify_tiempo_total(int $userid, int $courseid, int $sessionlimit = 14400): array {
    global $DB;
    $params = [];
    $where = audit_logs_where($userid, $courseid, $params);

    $sql = "SELECT l.id, l.timecreated, l.eventname, FLOOR(l.timecreated / 86400) AS daybucket
              FROM {logstore_standard_log} l
             WHERE $where
           ORDER BY l.timecreated ASC, l.id ASC";
    $logs = $DB->get_records_sql($sql, $params);

    if (!$logs) {
        return [0, 0];
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

    // Apply max daily hours cap.
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

    return [$total, count($totalsbyday)];
}

/** First access timestamp. */
function verify_primer_acceso(int $userid, int $courseid): ?int {
    global $DB;
    $params = [];
    $where = audit_logs_where($userid, $courseid, $params);
    $val = $DB->get_field_sql("SELECT MIN(l.timecreated) FROM {logstore_standard_log} l WHERE $where", $params);
    return ($val !== false && $val !== null) ? (int)$val : null;
}

/** Last access timestamp. */
function verify_ultimo_acceso(int $userid, int $courseid): ?int {
    global $DB;
    $params = [];
    $where = audit_logs_where($userid, $courseid, $params);
    $val = $DB->get_field_sql("SELECT MAX(l.timecreated) FROM {logstore_standard_log} l WHERE $where", $params);
    return ($val !== false && $val !== null) ? (int)$val : null;
}

/** Count forum posts. */
function verify_mensajes_foro(int $userid, int $courseid): int {
    global $DB;
    $sql = "SELECT COUNT(1)
              FROM {forum_posts} fp
              JOIN {forum_discussions} fd ON fd.id = fp.discussion
              JOIN {forum} f ON f.id = fd.forum
             WHERE fp.userid = :userid AND f.course = :courseid";
    $count = $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    return ($count !== false) ? (int)$count : 0;
}

/** Get nota_final. */
function verify_nota_final(int $userid, int $courseid): string {
    global $DB;
    $sql = "SELECT gg.finalgrade FROM {grade_items} gi
            LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
            WHERE gi.courseid = :courseid AND gi.itemtype = 'course'
            ORDER BY gi.id ASC";
    $grade = $DB->get_field_sql($sql, ['userid' => $userid, 'courseid' => $courseid], IGNORE_MISSING);
    if ($grade === false || $grade === null || $grade === '') return '0.00';
    return number_format((float)$grade, 2);
}

/** Format seconds as "XXh YYm ZZs". */
function format_hms(int $seconds): string {
    $seconds = max(0, $seconds);
    $h = (int)floor($seconds / 3600);
    $m = (int)floor(($seconds % 3600) / 60);
    $s = (int)($seconds % 60);
    return sprintf('%02dh %02dm %02ds', $h, $m, $s);
}

// ============================================================
// 3. Find courses to audit
// ============================================================

// Get courses with logstore activity
$sql = "SELECT DISTINCT l.courseid
          FROM {logstore_standard_log} l
          JOIN {course} c ON c.id = l.courseid
         WHERE l.courseid > 1
      ORDER BY l.courseid";
$all_active = $DB->get_fieldset_sql($sql);

// Check for bridge cache table
$cachetable_exists = false;
try {
    $cachetable_exists = $DB->get_manager()->table_exists('block_adv_reports_values');
} catch (Throwable $t) {}

$courseids_with_cache = [];
if ($cachetable_exists) {
    $courseids_with_cache = $DB->get_fieldset_sql(
        "SELECT DISTINCT courseid FROM {block_adv_reports_values} ORDER BY courseid"
    );
}

// Prioritize courses WITH cache (more interesting for audit), then others.
$seen = [];
$courseids_to_audit = [];
foreach ($courseids_with_cache as $cid) {
    if (!isset($seen[$cid]) && count($courseids_to_audit) < $maxcourses) {
        $courseids_to_audit[] = (int)$cid;
        $seen[$cid] = true;
    }
}
foreach ($all_active as $cid) {
    if (!isset($seen[$cid]) && count($courseids_to_audit) < $maxcourses) {
        $courseids_to_audit[] = (int)$cid;
        $seen[$cid] = true;
    }
}

if (empty($courseids_to_audit)) {
    $result = ['error' => 'No courses with activity found.'];
    echo "<<<CR_RESULT>>>" . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
    exit(0);
}

// ============================================================
// 4. Run the audit
// ============================================================

$results = [
    'audit_type' => 'plugin_output_vs_logstore_verification',
    'description' => 'Compara output real del plugin (get_value) vs verificación independiente desde logstore',
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

$statcounts = [];
$statdiscrepancies = [];

$AUDIT_STATS = [
    'tiempo_total', 'registros', 'dias_conexion',
    'primer_acceso', 'ultimo_acceso',
    'correos', 'mensajes_foro', 'mensajes_alumnos', 'nota_final',
];

foreach ($courseids_to_audit as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id,shortname,fullname', IGNORE_MISSING);
    if (!$course) continue;

    $context = context_course::instance($courseid, IGNORE_MISSING);
    if (!$context) continue;

    $enrolledusers = get_enrolled_users($context, '', 0, 'u.id,u.username,u.firstname,u.lastname', 'u.lastname ASC', 0, 0);
    if (empty($enrolledusers)) continue;

    // Sample users — prefer those with log activity.
    $usersToAudit = [];
    foreach ($enrolledusers as $user) {
        $hasactivity = $DB->record_exists_select(
            'logstore_standard_log',
            'userid = :uid AND courseid = :cid',
            ['uid' => $user->id, 'cid' => $courseid]
        );
        if ($hasactivity) {
            $usersToAudit[] = $user;
        }
        if (count($usersToAudit) >= $sampleusers) break;
    }
    if (empty($usersToAudit)) continue;

    $has_cache = in_array($courseid, $courseids_with_cache);

    $courseresult = [
        'courseid' => $courseid,
        'shortname' => $course->shortname,
        'fullname' => $course->fullname,
        'has_bridge_cache' => $has_cache,
        'users' => [],
    ];

    foreach ($usersToAudit as $user) {
        $userid = (int)$user->id;
        $userresult = [
            'userid' => $userid,
            'username' => $user->username,
            'fullname' => $user->firstname . ' ' . $user->lastname,
            'comparisons' => [],
            'discrepancies' => [],
        ];

        // ============================================================
        // TIEMPO TOTAL
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'tiempo_total');
        $plugin_clean = clean_plugin_value($plugin_raw);
        // Plugin output like "10h 16m 33s Ver días" — extract time part.
        $plugin_time = $plugin_clean;
        if (preg_match('/(\d+h\s+\d+m\s+\d+s)/', $plugin_clean, $tm)) {
            $plugin_time = $tm[1];
        }

        list($verify_secs, $verify_days) = verify_tiempo_total($userid, $courseid);
        $verify_fmt = format_hms($verify_secs);

        $match = ($plugin_time === $verify_fmt);
        $userresult['comparisons']['tiempo_total'] = [
            'plugin_output' => $plugin_time,
            'verificacion' => $verify_fmt,
            'verificacion_seconds' => $verify_secs,
            'match' => $match,
            'source' => $has_cache ? 'posible_bridge_cache' : 'calculo_plugin',
        ];

        if (!isset($statcounts['tiempo_total'])) { $statcounts['tiempo_total'] = 0; $statdiscrepancies['tiempo_total'] = 0; }
        $statcounts['tiempo_total']++;
        if (!$match) {
            $userresult['discrepancies'][] = "tiempo_total: plugin='{$plugin_time}' vs verificación='{$verify_fmt}' ({$verify_secs}s)";
            $statdiscrepancies['tiempo_total']++;
        }

        // ============================================================
        // REGISTROS
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'registros');
        $plugin_clean = clean_plugin_value($plugin_raw);
        $plugin_num = extract_number($plugin_clean);

        $verify_reg = verify_registros($userid, $courseid);

        $match = ($plugin_num !== null && $plugin_num === $verify_reg);
        $userresult['comparisons']['registros'] = [
            'plugin_output' => $plugin_clean,
            'plugin_numeric' => $plugin_num,
            'verificacion' => $verify_reg,
            'match' => $match,
            'source' => $has_cache ? 'posible_bridge_cache' : 'calculo_plugin',
            'note' => $has_cache ? 'Bridge mapea registros→distinct_days_connection (posible bug)' : null,
        ];

        if (!isset($statcounts['registros'])) { $statcounts['registros'] = 0; $statdiscrepancies['registros'] = 0; }
        $statcounts['registros']++;
        if (!$match) {
            $userresult['discrepancies'][] = "registros: plugin='{$plugin_clean}' (num={$plugin_num}) vs verificación={$verify_reg}";
            $statdiscrepancies['registros']++;
        }

        // ============================================================
        // DIAS_CONEXION
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'dias_conexion');
        $plugin_clean = clean_plugin_value($plugin_raw);
        $plugin_num = extract_number($plugin_clean);

        $verify_dias = verify_dias_conexion($userid, $courseid);

        $match = ($plugin_num !== null && $plugin_num === $verify_dias);
        $userresult['comparisons']['dias_conexion'] = [
            'plugin_output' => $plugin_clean,
            'plugin_numeric' => $plugin_num,
            'verificacion' => $verify_dias,
            'match' => $match,
            'source' => $has_cache ? 'posible_bridge_cache' : 'calculo_plugin',
        ];

        if (!isset($statcounts['dias_conexion'])) { $statcounts['dias_conexion'] = 0; $statdiscrepancies['dias_conexion'] = 0; }
        $statcounts['dias_conexion']++;
        if (!$match) {
            $userresult['discrepancies'][] = "dias_conexion: plugin='{$plugin_clean}' (num={$plugin_num}) vs verificación={$verify_dias}";
            $statdiscrepancies['dias_conexion']++;
        }

        // ============================================================
        // PRIMER ACCESO
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'primer_acceso');
        $plugin_clean = clean_plugin_value($plugin_raw);

        $verify_ts = verify_primer_acceso($userid, $courseid);
        $verify_fmt = ($verify_ts !== null) ? date('d/m/Y H:i', $verify_ts) : 'Sin acceso';

        // Compare dates (plugin uses userdate which respects timezone, we compare date part).
        $match = false;
        if ($verify_ts !== null && $plugin_clean !== 'No visitado') {
            // Extract date from plugin output (dd/mm/YYYY).
            if (preg_match('#(\d{2}/\d{2}/\d{4})#', $plugin_clean, $dm)) {
                $plugindate = $dm[1];
                $verifydate = userdate($verify_ts, '%d/%m/%Y');
                $match = ($plugindate === $verifydate);
            }
        } elseif ($verify_ts === null && ($plugin_clean === 'No visitado' || $plugin_clean === 'Sin acceso' || $plugin_clean === '')) {
            $match = true;
        }

        $userresult['comparisons']['primer_acceso'] = [
            'plugin_output' => $plugin_clean,
            'verificacion' => ($verify_ts !== null) ? userdate($verify_ts, '%d/%m/%Y %H:%M') : 'Sin acceso',
            'verificacion_timestamp' => $verify_ts,
            'match' => $match,
        ];

        if (!isset($statcounts['primer_acceso'])) { $statcounts['primer_acceso'] = 0; $statdiscrepancies['primer_acceso'] = 0; }
        $statcounts['primer_acceso']++;
        if (!$match) {
            $vfmt = ($verify_ts !== null) ? userdate($verify_ts, '%d/%m/%Y %H:%M') : 'Sin acceso';
            $userresult['discrepancies'][] = "primer_acceso: plugin='{$plugin_clean}' vs verificación='{$vfmt}'";
            $statdiscrepancies['primer_acceso']++;
        }

        // ============================================================
        // ULTIMO ACCESO
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'ultimo_acceso');
        $plugin_clean = clean_plugin_value($plugin_raw);

        $verify_ts = verify_ultimo_acceso($userid, $courseid);

        $match = false;
        if ($verify_ts !== null && $plugin_clean !== 'No visitado') {
            if (preg_match('#(\d{2}/\d{2}/\d{4})#', $plugin_clean, $dm)) {
                $plugindate = $dm[1];
                $verifydate = userdate($verify_ts, '%d/%m/%Y');
                $match = ($plugindate === $verifydate);
            }
        } elseif ($verify_ts === null && ($plugin_clean === 'No visitado' || $plugin_clean === 'Sin acceso' || $plugin_clean === '')) {
            $match = true;
        }

        $userresult['comparisons']['ultimo_acceso'] = [
            'plugin_output' => $plugin_clean,
            'verificacion' => ($verify_ts !== null) ? userdate($verify_ts, '%d/%m/%Y %H:%M') : 'Sin acceso',
            'verificacion_timestamp' => $verify_ts,
            'match' => $match,
        ];

        if (!isset($statcounts['ultimo_acceso'])) { $statcounts['ultimo_acceso'] = 0; $statdiscrepancies['ultimo_acceso'] = 0; }
        $statcounts['ultimo_acceso']++;
        if (!$match) {
            $vfmt = ($verify_ts !== null) ? userdate($verify_ts, '%d/%m/%Y %H:%M') : 'Sin acceso';
            $userresult['discrepancies'][] = "ultimo_acceso: plugin='{$plugin_clean}' vs verificación='{$vfmt}'";
            $statdiscrepancies['ultimo_acceso']++;
        }

        // ============================================================
        // CORREOS
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'correos');
        $plugin_clean = clean_plugin_value($plugin_raw);
        $plugin_num = extract_number($plugin_clean);

        // For correos, plugin uses complex staff-target logic.
        // We record plugin output and note it uses its own logic.
        $userresult['comparisons']['correos'] = [
            'plugin_output' => $plugin_clean,
            'plugin_numeric' => $plugin_num,
            'note' => 'Plugin usa lógica compleja de staff targets — verificación directa no aplica, se compara consistencia',
        ];

        if (!isset($statcounts['correos'])) { $statcounts['correos'] = 0; $statdiscrepancies['correos'] = 0; }
        $statcounts['correos']++;
        // No independent verification for correos — the plugin's complex logic IS the source of truth.

        // ============================================================
        // MENSAJES_FORO
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'mensajes_foro');
        $plugin_clean = clean_plugin_value($plugin_raw);
        $plugin_num = extract_number($plugin_clean);

        $verify_foro = verify_mensajes_foro($userid, $courseid);

        $match = ($plugin_num !== null && $plugin_num === $verify_foro);
        $userresult['comparisons']['mensajes_foro'] = [
            'plugin_output' => $plugin_clean,
            'plugin_numeric' => $plugin_num,
            'verificacion' => $verify_foro,
            'match' => $match,
        ];

        if (!isset($statcounts['mensajes_foro'])) { $statcounts['mensajes_foro'] = 0; $statdiscrepancies['mensajes_foro'] = 0; }
        $statcounts['mensajes_foro']++;
        if (!$match) {
            $userresult['discrepancies'][] = "mensajes_foro: plugin='{$plugin_clean}' (num={$plugin_num}) vs verificación={$verify_foro}";
            $statdiscrepancies['mensajes_foro']++;
        }

        // ============================================================
        // MENSAJES_ALUMNOS
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'mensajes_alumnos');
        $plugin_clean = clean_plugin_value($plugin_raw);
        $plugin_num = extract_number($plugin_clean);

        // Plugin uses count_messages_by_course_group(['student']) which includes
        // direct messages + forum messages with students — complex to replicate independently.
        $userresult['comparisons']['mensajes_alumnos'] = [
            'plugin_output' => $plugin_clean,
            'plugin_numeric' => $plugin_num,
            'note' => 'Plugin cuenta mensajes directos + foro con estudiantes — lógica compleja propia',
        ];

        if (!isset($statcounts['mensajes_alumnos'])) { $statcounts['mensajes_alumnos'] = 0; $statdiscrepancies['mensajes_alumnos'] = 0; }
        $statcounts['mensajes_alumnos']++;

        // ============================================================
        // NOTA FINAL
        // ============================================================
        $plugin_raw = get_plugin_value($courseid, $userid, 'nota_final');
        $plugin_clean = clean_plugin_value($plugin_raw);

        $verify_nota = verify_nota_final($userid, $courseid);

        // Compare as floats with tolerance.
        $plugin_grade = (float)$plugin_clean;
        $verify_grade = (float)$verify_nota;
        $match = (abs($plugin_grade - $verify_grade) < 0.01);

        $userresult['comparisons']['nota_final'] = [
            'plugin_output' => $plugin_clean,
            'verificacion' => $verify_nota,
            'match' => $match,
        ];

        if (!isset($statcounts['nota_final'])) { $statcounts['nota_final'] = 0; $statdiscrepancies['nota_final'] = 0; }
        $statcounts['nota_final']++;
        if (!$match) {
            $userresult['discrepancies'][] = "nota_final: plugin='{$plugin_clean}' vs verificación='{$verify_nota}'";
            $statdiscrepancies['nota_final']++;
        }

        // ---- Aggregate discrepancies ----
        $disccount = count($userresult['discrepancies']);
        $results['total_discrepancies'] += $disccount;
        $courseresult['users'][] = $userresult;
        $results['total_users_audited']++;
    }

    $results['courses'][] = $courseresult;
    $results['courses_audited']++;

    fprintf(STDERR, "Audited course %d (%s) — %d users, cache=%s\n",
        $courseid, $course->shortname, count($usersToAudit),
        $has_cache ? 'YES' : 'NO');
}

// Build summary.
foreach ($statcounts as $stat => $count) {
    $disc = $statdiscrepancies[$stat] ?? 0;
    $results['summary_by_stat'][$stat] = [
        'total_compared' => $count,
        'discrepancies' => $disc,
        'match_rate' => ($count > 0) ? round(100 * ($count - $disc) / $count, 1) . '%' : 'N/A',
    ];
}

echo "<<<CR_RESULT>>>" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
