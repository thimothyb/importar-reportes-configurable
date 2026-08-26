<?php
/**
 * Explorar TODOS los cursos con datos iTOP, mostrando en qué tablas tienen registros.
 *
 * Uso:
 *   sudo -u www-data php /var/www/html/moodle/sanase/blocks/configurable_reports/explorar_itop_global.php
 */

define('CLI_SCRIPT', true);
$moodleroot = dirname(dirname(__DIR__));
if (!file_exists($moodleroot . '/config.php')) {
    $moodleroot = '/var/www/html/moodle/sanase';
}
require($moodleroot . '/config.php');
global $DB;

$dbman = $DB->get_manager();

echo "\n=== EXPLORACIÓN GLOBAL iTOP — TODOS LOS CURSOS ===\n\n";

// Tablas y su columna de curso
$tablas = [
    'block_adv_reports_times'      => 'course',
    'block_adv_reports_usrstats'   => 'courseid',
    'block_adv_reports_values'     => 'courseid',
    'block_adv_reports_daily'      => 'courseid',
    'block_adv_reports_sco_times'  => 'course',
    'block_adv_reports_videoconf'  => 'courseid',
    'block_adv_reports_chours'     => 'courseid',
    'block_adv_reports_tmethod'    => 'courseid',
    'block_adv_reports_sect_times' => 'courseid',
    'block_adv_reports_cert'       => 'courseid',
];

// 1. Total de registros por tabla
echo "--- 1. TOTAL REGISTROS POR TABLA ---\n";
printf("  %-40s %10s\n", 'TABLA', 'REGISTROS');
printf("  %-40s %10s\n", str_repeat('-', 40), '----------');
foreach ($tablas as $tabla => $col) {
    if (!$dbman->table_exists($tabla)) {
        printf("  %-40s %10s\n", $tabla, 'NO EXISTE');
        continue;
    }
    try {
        $count = $DB->count_records($tabla);
        printf("  %-40s %10d\n", $tabla, $count);
    } catch (\Exception $e) {
        printf("  %-40s %10s\n", $tabla, 'ERROR');
    }
}

// 2. Cursos por tabla
echo "\n--- 2. CURSOS CON DATOS POR TABLA ---\n";
$allcourses = [];
foreach ($tablas as $tabla => $col) {
    if (!$dbman->table_exists($tabla)) continue;
    try {
        $courses = $DB->get_fieldset_sql("SELECT DISTINCT {$col} FROM {{$tabla}}");
        echo "  $tabla: " . count($courses) . " cursos";
        if (count($courses) > 0 && count($courses) <= 30) {
            echo " → [" . implode(', ', $courses) . "]";
        }
        echo "\n";
        foreach ($courses as $c) {
            $allcourses[(int)$c][$tabla] = true;
        }
    } catch (\Exception $e) {
        echo "  $tabla: ERROR - " . $e->getMessage() . "\n";
    }
}

// 3. Matriz curso x tabla
echo "\n--- 3. MATRIZ CURSO × TABLA (resumen) ---\n";
if (empty($allcourses)) {
    echo "  (sin datos en ninguna tabla)\n";
} else {
    ksort($allcourses);
    // Cabecera compacta
    $shortnames = [
        'block_adv_reports_times'      => 'times',
        'block_adv_reports_usrstats'   => 'usrst',
        'block_adv_reports_values'     => 'values',
        'block_adv_reports_daily'      => 'daily',
        'block_adv_reports_sco_times'  => 'scorm',
        'block_adv_reports_videoconf'  => 'video',
        'block_adv_reports_chours'     => 'chrs',
        'block_adv_reports_tmethod'    => 'tmeth',
        'block_adv_reports_sect_times' => 'sect',
        'block_adv_reports_cert'       => 'cert',
    ];

    printf("  %-8s", 'CURSO');
    foreach ($shortnames as $full => $short) {
        printf(" %-6s", $short);
    }
    echo "\n";
    printf("  %-8s", str_repeat('-', 8));
    foreach ($shortnames as $full => $short) {
        printf(" %-6s", '------');
    }
    echo "\n";

    foreach ($allcourses as $cid => $tables) {
        printf("  %-8d", $cid);
        foreach ($shortnames as $full => $short) {
            $has = isset($tables[$full]) ? '  ✓' : '  ·';
            printf(" %-6s", $has);
        }

        // Obtener nombre del curso
        $course = $DB->get_record('course', ['id' => $cid], 'shortname', IGNORE_MISSING);
        if ($course) {
            echo "  " . substr($course->shortname, 0, 40);
        }
        echo "\n";
    }
}

// 4. Stats distintos en values (global)
echo "\n--- 4. TODOS LOS STATS EN block_adv_reports_values (global) ---\n";
if ($dbman->table_exists('block_adv_reports_values')) {
    $rows = $DB->get_records_sql(
        "SELECT stat, COUNT(DISTINCT courseid) as cursos, COUNT(*) as registros
         FROM {block_adv_reports_values}
         GROUP BY stat
         ORDER BY stat"
    );
    if ($rows) {
        printf("  %-45s %8s %10s\n", 'STAT', 'CURSOS', 'REGISTROS');
        printf("  %-45s %8s %10s\n", str_repeat('-', 45), '--------', '----------');
        foreach ($rows as $r) {
            printf("  %-45s %8d %10d\n", $r->stat, $r->cursos, $r->registros);
        }
    }
}

// 5. Stats distintos en usrstats (global)
echo "\n--- 5. TODOS LOS STATS EN block_adv_reports_usrstats (global) ---\n";
if ($dbman->table_exists('block_adv_reports_usrstats')) {
    $rows = $DB->get_records_sql(
        "SELECT stat, COUNT(DISTINCT courseid) as cursos, COUNT(*) as registros
         FROM {block_adv_reports_usrstats}
         GROUP BY stat
         ORDER BY stat"
    );
    if ($rows) {
        printf("  %-45s %8s %10s\n", 'STAT', 'CURSOS', 'REGISTROS');
        printf("  %-45s %8s %10s\n", str_repeat('-', 45), '--------', '----------');
        foreach ($rows as $r) {
            printf("  %-45s %8d %10d\n", $r->stat, $r->cursos, $r->registros);
        }
    } else {
        echo "  (sin datos globales)\n";
    }
}

echo "\n=== FIN EXPLORACIÓN GLOBAL ===\n";
