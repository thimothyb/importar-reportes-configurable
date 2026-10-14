<?php
/**
 * Ocultar bloques de Configurable Reports en cursos con datos iTOP.
 *
 * Busca cursos que tienen cache del bridge (block_adv_reports_values)
 * y oculta sus instancias de block_configurable_reports poniendo
 * block_instances.visible = 0.
 *
 * En Moodle, visible=0 significa:
 *   - Oculto para estudiantes (no ven el bloque).
 *   - Visible para profesores/admins con moodle/block:view capability.
 *
 * Modo: --dry-run (por defecto) solo muestra qué haría.
 *       --apply   ejecuta los cambios.
 *       --restore deshace: pone visible=1 en los mismos bloques.
 *
 * Uso: php ocultar_informes_itop.php [--dry-run|--apply|--restore]
 *
 * Salida: JSON envuelto en <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');

global $DB;

// Parse mode.
$mode = 'dry-run';
if (isset($argv[1])) {
    $arg = ltrim($argv[1], '-');
    if (in_array($arg, ['apply', 'restore', 'dry-run', 'dryrun'])) {
        $mode = str_replace('dryrun', 'dry-run', $arg);
    }
}

$results = [
    'action'    => 'ocultar_informes_itop',
    'mode'      => $mode,
    'timestamp' => date('Y-m-d H:i:s'),
    'server'    => gethostname(),
];

// ============================================================
// 1. Find courses with iTOP bridge cache data
// ============================================================

$sql_itop_courses = "
    SELECT DISTINCT courseid
    FROM {block_adv_reports_values}
    WHERE courseid IS NOT NULL
    ORDER BY courseid
";
$itop_courseids = $DB->get_fieldset_sql($sql_itop_courses);

if (empty($itop_courseids)) {
    $results['error'] = 'No se encontraron cursos con datos iTOP (block_adv_reports_values vacía).';
    echo "<<<CR_RESULT>>>" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
    exit(0);
}

$results['total_itop_courses'] = count($itop_courseids);

// ============================================================
// 2. Find block_instances of configurable_reports in those courses
// ============================================================

// block_instances links to course via parentcontextid → context.
// context: contextlevel=50 (CONTEXT_COURSE), instanceid=courseid.

list($in_sql, $params) = $DB->get_in_or_equal($itop_courseids, SQL_PARAMS_NAMED, 'cid');

$sql_blocks = "
    SELECT bi.id, bi.visible, bi.pagetypepattern,
           ctx.instanceid AS courseid,
           c.shortname, c.fullname
    FROM {block_instances} bi
    JOIN {context} ctx ON ctx.id = bi.parentcontextid AND ctx.contextlevel = 50
    JOIN {course} c ON c.id = ctx.instanceid
    WHERE bi.blockname = 'configurable_reports'
      AND ctx.instanceid {$in_sql}
    ORDER BY ctx.instanceid, bi.id
";

$blocks = $DB->get_records_sql($sql_blocks, $params);

if (empty($blocks)) {
    $results['error'] = 'No se encontraron bloques configurable_reports en cursos con datos iTOP.';
    $results['itop_courses_checked'] = count($itop_courseids);
    echo "<<<CR_RESULT>>>" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
    exit(0);
}

$results['total_blocks_found'] = count($blocks);

// ============================================================
// 3. Classify and act
// ============================================================

$already_hidden = 0;
$to_change = [];
$changed = [];
$courses_affected = [];

foreach ($blocks as $block) {
    if ($mode === 'restore') {
        // Restore: set visible=1 for hidden blocks.
        if ((int)$block->visible === 0) {
            $to_change[] = $block;
        }
    } else {
        // Hide: set visible=0 for visible blocks.
        if ((int)$block->visible === 1) {
            $to_change[] = $block;
        } else {
            $already_hidden++;
        }
    }
}

$target_visible = ($mode === 'restore') ? 1 : 0;
$action_verb = ($mode === 'restore') ? 'restaurar' : 'ocultar';

$results['already_in_target_state'] = ($mode === 'restore')
    ? (count($blocks) - count($to_change))
    : $already_hidden;
$results['blocks_to_' . ($mode === 'restore' ? 'restore' : 'hide')] = count($to_change);

if ($mode === 'apply' || $mode === 'restore') {
    foreach ($to_change as $block) {
        $DB->set_field('block_instances', 'visible', $target_visible, ['id' => $block->id]);
        $courses_affected[$block->courseid] = $block->shortname ?: $block->fullname;
        $changed[] = [
            'block_instance_id' => $block->id,
            'courseid'          => $block->courseid,
            'shortname'         => $block->shortname,
            'old_visible'       => (int)$block->visible,
            'new_visible'       => $target_visible,
        ];
    }
    $results['blocks_changed'] = count($changed);
    $results['courses_affected'] = count($courses_affected);
    $results['details'] = $changed;

    // Purge Moodle caches so the change takes effect immediately.
    if (function_exists('purge_all_caches')) {
        purge_all_caches();
        $results['caches_purged'] = true;
    }
} else {
    // Dry-run: just list what would change.
    $preview = [];
    foreach ($to_change as $block) {
        $courses_affected[$block->courseid] = $block->shortname ?: $block->fullname;
        $preview[] = [
            'block_instance_id' => $block->id,
            'courseid'          => $block->courseid,
            'shortname'         => $block->shortname,
            'current_visible'   => (int)$block->visible,
            'would_set'         => $target_visible,
        ];
    }
    $results['courses_would_affect'] = count($courses_affected);
    $results['preview'] = $preview;
}

$results['course_list'] = $courses_affected;

// ============================================================
// 4. Also list block_configurable_reports (report records) in those courses
// ============================================================

$sql_reports = "
    SELECT id, courseid, type, name
    FROM {block_configurable_reports}
    WHERE courseid {$in_sql}
    ORDER BY courseid, name
";
$reports = $DB->get_records_sql($sql_reports, $params);

$reports_by_course = [];
foreach ($reports as $r) {
    if (!isset($reports_by_course[$r->courseid])) {
        $reports_by_course[$r->courseid] = [];
    }
    $reports_by_course[$r->courseid][] = [
        'id'   => $r->id,
        'name' => $r->name,
        'type' => $r->type,
    ];
}
$results['total_reports_in_itop_courses'] = count($reports);
$results['reports_by_course_count'] = array_map('count', $reports_by_course);

echo "<<<CR_RESULT>>>" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "<<<END_CR_RESULT>>>";
