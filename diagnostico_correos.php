<?php
/**
 * Diagnóstico: analiza cómo se calcula "correos" para un usuario/curso específico.
 * Consulta block_adv_reports_values (cache iTOP) y las tablas de mensajes reales
 * para deducir la lógica de conteo de iTOP.
 *
 * Uso: php diagnostico_correos.php --config=/path/to/config.php --userid=2430 --courseid=450
 */

// --- Parse CLI args ---
$options = getopt('', ['config:', 'userid:', 'courseid:']);
if (empty($options['config']) || empty($options['userid']) || empty($options['courseid'])) {
    fwrite(STDERR, "Uso: php diagnostico_correos.php --config=<config.php> --userid=<id> --courseid=<id>\n");
    exit(1);
}

$userid   = (int)$options['userid'];
$courseid = (int)$options['courseid'];

define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG', false);
require($options['config']);
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/accesslib.php');

global $DB;
$dbman = $DB->get_manager();

$result = [
    'userid'   => $userid,
    'courseid' => $courseid,
    'cache'    => [],
    'counts'   => [],
    'details'  => [],
];

// ============================================================
// 1. CACHE iTOP: todos los registros para este usuario/curso
// ============================================================
if ($dbman->table_exists('block_adv_reports_values')) {
    $records = $DB->get_records('block_adv_reports_values', [
        'courseid' => $courseid,
        'userid'   => $userid,
    ], 'stat ASC, reportid ASC');

    foreach ($records as $r) {
        $result['cache'][] = [
            'reportid' => (int)$r->reportid,
            'stat'     => $r->stat,
            'value'    => $r->value,
            'clean'    => trim(strip_tags($r->value)),
        ];
    }
} else {
    $result['cache_error'] = 'table block_adv_reports_values does not exist';
}

// ============================================================
// 2. Identificar roles del usuario en el curso
// ============================================================
try {
    $coursecontext = context_course::instance($courseid);
    $roles = get_user_roles($coursecontext, $userid, false);
    $userroles = [];
    foreach ($roles as $role) {
        $userroles[] = ['roleid' => (int)$role->roleid, 'shortname' => $role->shortname];
    }
    $result['user_roles'] = $userroles;
} catch (Throwable $e) {
    $result['user_roles_error'] = $e->getMessage();
}

// ============================================================
// 3. Identificar staff del curso (teachers, managers, non-students)
// ============================================================
$staffids = [];
try {
    $coursecontext = context_course::instance($courseid);
    $contextpath = $coursecontext->path;
    $contextids = [];
    foreach (explode('/', trim($contextpath, '/')) as $chunk) {
        $id = (int)$chunk;
        if ($id > 0) $contextids[] = $id;
    }
    $contextids[] = (int)$coursecontext->id;
    $contextids = array_unique($contextids);

    if (!empty($contextids)) {
        [$ctxinsql, $ctxparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

        // Teachers and managers by archetype
        $sql = "SELECT DISTINCT ra.userid, r.shortname, r.archetype
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ctx.id $ctxinsql
                   AND r.archetype IN ('editingteacher', 'teacher', 'manager')
                   AND ra.userid <> :excludeuid";
        $rows = $DB->get_records_sql($sql, array_merge($ctxparams, ['excludeuid' => $userid]));
        foreach ($rows as $row) {
            $staffids[(int)$row->userid] = (int)$row->userid;
        }

        // Non-student roles
        $sql2 = "SELECT DISTINCT ra.userid, r.shortname, r.archetype
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                   JOIN {context} ctx ON ctx.id = ra.contextid
                  WHERE ctx.id $ctxinsql
                    AND (r.archetype IS NULL OR r.archetype = '' OR r.archetype <> 'student')
                    AND ra.userid <> :excludeuid2";
        $rows2 = $DB->get_records_sql($sql2, array_merge($ctxparams, ['excludeuid2' => $userid]));
        foreach ($rows2 as $row) {
            $staffids[(int)$row->userid] = (int)$row->userid;
        }
    }

    // By capability
    $capusers = get_enrolled_users($coursecontext, 'moodle/course:update', 0, 'u.id');
    foreach ($capusers as $u) {
        $uid = (int)$u->id;
        if ($uid > 0 && $uid !== $userid) {
            $staffids[$uid] = $uid;
        }
    }
} catch (Throwable $e) {
    $result['staff_error'] = $e->getMessage();
}
$staffids = array_values(array_unique($staffids));
$result['staff_ids'] = $staffids;
$result['staff_count'] = count($staffids);

// Nombres del staff
$staffnames = [];
if (!empty($staffids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($staffids, SQL_PARAMS_NAMED, 'sname');
    $users = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $insql", $inparams);
    foreach ($users as $u) {
        $staffnames[(int)$u->id] = trim($u->firstname . ' ' . $u->lastname);
    }
}
$result['staff_names'] = $staffnames;

// Nombre del usuario objetivo
$targetuser = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname');
$result['user_name'] = $targetuser ? trim($targetuser->firstname . ' ' . $targetuser->lastname) : '?';

// ============================================================
// 4. CONTEOS DE MENSAJES con distintas metodologías
// ============================================================

// --- 4a. Solo teachers by archetype (editingteacher, teacher) ---
$teacherids = [];
if (!empty($contextids)) {
    [$ctxinsql2, $ctxparams2] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx2');
    $sql = "SELECT DISTINCT ra.userid
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
              JOIN {context} ctx ON ctx.id = ra.contextid
             WHERE ctx.id $ctxinsql2
               AND r.archetype IN ('editingteacher', 'teacher')
               AND ra.userid <> :excludeuid3";
    $rows = $DB->get_records_sql($sql, array_merge($ctxparams2, ['excludeuid3' => $userid]));
    foreach ($rows as $row) $teacherids[] = (int)$row->userid;
}
$result['teacher_ids'] = $teacherids;

// Function to count direct messages using conversation tables
function count_conversation_messages(int $uid, array $targetids): int {
    global $DB;
    $dbman = $DB->get_manager();
    if (empty($targetids)) return 0;

    // Try message_messages first
    if ($dbman->table_exists('message_messages') && $dbman->table_exists('message_conversation_members')) {
        [$insql, $inparams] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg1');
        [$insql2, $inparams2] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg2');
        $sql = "SELECT COUNT(DISTINCT mm.id) AS total
                  FROM {message_messages} mm
                  JOIN {message_conversation_members} me
                    ON me.conversationid = mm.conversationid AND me.userid = :userid
                  JOIN {message_conversation_members} mt
                    ON mt.conversationid = mm.conversationid AND mt.userid $insql
                 WHERE (mm.useridfrom = :useridfrom OR mm.useridfrom $insql2)";
        $params = array_merge(['userid' => $uid, 'useridfrom' => $uid], $inparams, $inparams2);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    // Try legacy message table
    if ($dbman->table_exists('message')) {
        [$insql, $inparams] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg3');
        [$insql2, $inparams2] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg4');
        $sql = "SELECT COUNT(1) AS total
                  FROM {message} m
                 WHERE ((m.useridfrom = :ufrom AND m.useridto $insql)
                    OR  (m.useridto = :uto AND m.useridfrom $insql2))";
        $params = array_merge(['ufrom' => $uid, 'uto' => $uid], $inparams, $inparams2);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    // Try messages table (Moodle 3.5-3.6)
    if ($dbman->table_exists('messages') && $dbman->table_exists('message_conversation_members')) {
        [$insql, $inparams] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg5');
        [$insql2, $inparams2] = $DB->get_in_or_equal($targetids, SQL_PARAMS_NAMED, 'tg6');
        $sql = "SELECT COUNT(DISTINCT m.id) AS total
                  FROM {messages} m
                  JOIN {message_conversation_members} me
                    ON me.conversationid = m.conversationid AND me.userid = :userid2
                  JOIN {message_conversation_members} mt
                    ON mt.conversationid = m.conversationid AND mt.userid $insql
                 WHERE (m.useridfrom = :useridfrom2 OR m.useridfrom $insql2)";
        $params = array_merge(['userid2' => $uid, 'useridfrom2' => $uid], $inparams, $inparams2);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    return 0;
}

// Count ONLY from teacher→student direction (what iTOP might count)
function count_teacher_to_student_messages(int $studentid, array $teacherids): int {
    global $DB;
    $dbman = $DB->get_manager();
    if (empty($teacherids)) return 0;

    if ($dbman->table_exists('message_messages') && $dbman->table_exists('message_conversation_members')) {
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tfrom');
        $sql = "SELECT COUNT(DISTINCT mm.id) AS total
                  FROM {message_messages} mm
                  JOIN {message_conversation_members} me
                    ON me.conversationid = mm.conversationid AND me.userid = :studentid
                 WHERE mm.useridfrom $insql";
        $params = array_merge(['studentid' => $studentid], $inparams);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    if ($dbman->table_exists('message')) {
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tfrom2');
        $sql = "SELECT COUNT(1) AS total FROM {message} m
                 WHERE m.useridto = :studentid2 AND m.useridfrom $insql";
        $params = array_merge(['studentid2' => $studentid], $inparams);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    return 0;
}

// Count student→teacher direction
function count_student_to_teacher_messages(int $studentid, array $teacherids): int {
    global $DB;
    $dbman = $DB->get_manager();
    if (empty($teacherids)) return 0;

    if ($dbman->table_exists('message_messages') && $dbman->table_exists('message_conversation_members')) {
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tto');
        $sql = "SELECT COUNT(DISTINCT mm.id) AS total
                  FROM {message_messages} mm
                  JOIN {message_conversation_members} mt
                    ON mt.conversationid = mm.conversationid AND mt.userid $insql
                 WHERE mm.useridfrom = :studentid";
        $params = array_merge(['studentid' => $studentid], $inparams);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    if ($dbman->table_exists('message')) {
        [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'tto2');
        $sql = "SELECT COUNT(1) AS total FROM {message} m
                 WHERE m.useridfrom = :studentid2 AND m.useridto $insql";
        $params = array_merge(['studentid2' => $studentid], $inparams);
        try {
            $rec = $DB->get_record_sql($sql, $params);
            if (!empty($rec->total)) return (int)$rec->total;
        } catch (Throwable $e) {}
    }

    return 0;
}

// Count forum posts from staff in this course
function count_forum_staff_posts(int $courseid, int $excludeuid, array $staffids): int {
    global $DB;
    if (empty($staffids) || $courseid <= 0) return 0;

    [$insql, $inparams] = $DB->get_in_or_equal($staffids, SQL_PARAMS_NAMED, 'fstaff');
    $sql = "SELECT COUNT(fp.id) AS total
              FROM {forum_posts} fp
              JOIN {forum_discussions} fd ON fd.id = fp.discussion
              JOIN {forum} f ON f.id = fd.forum
             WHERE f.course = :courseid AND fp.userid $insql AND fp.userid <> :excludeuid";
    $params = array_merge(['courseid' => $courseid, 'excludeuid' => $excludeuid], $inparams);
    try {
        $rec = $DB->get_record_sql($sql, $params);
        return !empty($rec->total) ? (int)$rec->total : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

// --- Run all counts ---
$counts = [];

// A: Direct messages bidirectional with teachers only
$counts['direct_with_teachers_bidir'] = count_conversation_messages($userid, $teacherids);

// B: Direct messages teacher→student only
$counts['direct_teacher_to_student'] = count_teacher_to_student_messages($userid, $teacherids);

// C: Direct messages student→teacher only
$counts['direct_student_to_teacher'] = count_student_to_teacher_messages($userid, $teacherids);

// D: Direct messages bidirectional with ALL staff
$counts['direct_with_allstaff_bidir'] = count_conversation_messages($userid, $staffids);

// E: Teacher→student only (all staff)
$counts['direct_allstaff_to_student'] = count_teacher_to_student_messages($userid, $staffids);

// F: Forum posts from teachers in course
$counts['forum_from_teachers'] = count_forum_staff_posts($courseid, $userid, $teacherids);

// G: Forum posts from ALL staff in course
$counts['forum_from_allstaff'] = count_forum_staff_posts($courseid, $userid, $staffids);

// Combinations
$counts['teachers_bidir_plus_forum_teachers'] = $counts['direct_with_teachers_bidir'] + $counts['forum_from_teachers'];
$counts['teachers_bidir_plus_forum_allstaff'] = $counts['direct_with_teachers_bidir'] + $counts['forum_from_allstaff'];
$counts['allstaff_bidir_plus_forum_allstaff'] = $counts['direct_with_allstaff_bidir'] + $counts['forum_from_allstaff'];
$counts['teacher_to_student_plus_forum']      = $counts['direct_teacher_to_student'] + $counts['forum_from_teachers'];

$result['counts'] = $counts;

// ============================================================
// 5. Show actual messages for reference (first 20)
// ============================================================
$messages = [];

// Direct messages with teachers
if (!empty($teacherids) && $dbman->table_exists('message_messages') && $dbman->table_exists('message_conversation_members')) {
    [$insql, $inparams] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'det1');
    [$insql2, $inparams2] = $DB->get_in_or_equal($teacherids, SQL_PARAMS_NAMED, 'det2');
    $sql = "SELECT mm.id, mm.useridfrom, mm.timecreated,
                   SUBSTRING(mm.fullmessage, 1, 100) AS msg_preview
              FROM {message_messages} mm
              JOIN {message_conversation_members} me
                ON me.conversationid = mm.conversationid AND me.userid = :userid
              JOIN {message_conversation_members} mt
                ON mt.conversationid = mm.conversationid AND mt.userid $insql
             WHERE (mm.useridfrom = :useridfrom OR mm.useridfrom $insql2)
          ORDER BY mm.timecreated DESC
             LIMIT 20";
    $params = array_merge(['userid' => $userid, 'useridfrom' => $userid], $inparams, $inparams2);
    try {
        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $fromname = isset($staffnames[(int)$row->useridfrom])
                ? $staffnames[(int)$row->useridfrom]
                : ($result['user_name'] ?? 'user#' . $row->useridfrom);
            $messages[] = [
                'type'     => 'direct',
                'id'       => (int)$row->id,
                'from'     => $fromname,
                'from_id'  => (int)$row->useridfrom,
                'preview'  => trim(strip_tags($row->msg_preview)),
                'time'     => date('Y-m-d H:i', (int)$row->timecreated),
            ];
        }
    } catch (Throwable $e) {
        $result['messages_error'] = $e->getMessage();
    }
}

$result['messages_sample'] = $messages;

// ============================================================
// Output
// ============================================================
echo "<<<CR_RESULT>>>";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "<<<END_CR_RESULT>>>";
