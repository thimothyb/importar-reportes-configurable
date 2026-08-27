<?php
/**
 * Diagnostico: qué stats tiene el cache iTOP para un curso y usuario.
 * Uso: php diagnostico_bridge_cache.php <courseid> [userid]
 */
define('CLI_SCRIPT', true);
require('/var/www/html/moodle/sanase/config.php');

$courseid = isset($argv[1]) ? (int)$argv[1] : 67;
$userid = isset($argv[2]) ? (int)$argv[2] : 0;

$table = 'block_adv_reports_values';
$dbman = $DB->get_manager();
if (!$dbman->table_exists($table)) {
    echo "ERROR: Tabla {$table} no existe.\n";
    exit(1);
}

echo "=== DIAGNOSTICO BRIDGE CACHE ===\n";
echo "Curso: {$courseid}\n\n";

// 1. Stats distintos en el cache para este curso
$sql = "SELECT DISTINCT stat FROM {{$table}} WHERE courseid = :courseid ORDER BY stat";
$stats = $DB->get_fieldset_sql($sql, ['courseid' => $courseid]);
echo "--- Stats disponibles en cache (curso {$courseid}) ---\n";
foreach ($stats as $s) {
    $count = $DB->count_records_select($table, "courseid = :cid AND stat = :stat AND value IS NOT NULL AND value != ''",
        ['cid' => $courseid, 'stat' => $s]);
    echo "  {$s}: {$count} registros con valor\n";
}

// 2. Si se especificó usuario, mostrar todos sus valores
if ($userid > 0) {
    echo "\n--- Valores para usuario {$userid} ---\n";
    $records = $DB->get_records_select($table,
        "courseid = :cid AND userid = :uid",
        ['cid' => $courseid, 'uid' => $userid],
        'reportid ASC, stat ASC');
    foreach ($records as $r) {
        $val = mb_substr($r->value, 0, 80);
        echo "  reportid={$r->reportid} | stat={$r->stat} | value={$val}\n";
    }
}

// 3. Mapeo bridge vs cache
echo "\n--- Bridge STAT_MAP vs Cache ---\n";
$statmap = [
    'tiempo_total' => 'platformdedicationtime',
    'actividades_aprendizaje' => 'assignment_num_completed_vs_total',
    'contenidos_visualizados' => 'course_modules_visited',
    'evaluaciones' => 'quiz_completed_vs_total_moodle_criteria',
    'dias_conexion' => 'distinct_days_connection',
    'primer_acceso' => 'first_connection',
    'ultimo_acceso' => 'last_connection',
    'interacciones_foros' => 'interactions_with_forums',
    'correos' => 'teacher_num_messages_with_students',
    'mensajes_alumnos' => 'total_messages',
    'ips_utilizadas' => 'distinct_ips',
    'ultima_ip' => 'last_ip',
    'registros' => 'distinct_days_connection',
];

foreach ($statmap as $pluginstat => $itopstat) {
    $exists = in_array($itopstat, $stats);
    $status = $exists ? 'EN CACHE' : 'NO EN CACHE';
    echo "  {$pluginstat} -> {$itopstat}: {$status}\n";
}

echo "\nDone.\n";
