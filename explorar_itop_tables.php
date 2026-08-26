<?php
/**
 * Explorar tablas iTOP legacy para un curso.
 *
 * Uso desde el servidor:
 *   sudo -u www-data php /var/www/html/moodle/sanase/blocks/configurable_reports/explorar_itop_tables.php --courseid=67
 *
 * O copiar al directorio del plugin y ejecutar.
 */

define('CLI_SCRIPT', true);

// Detectar la raíz de Moodle (subir desde blocks/configurable_reports/).
$moodleroot = dirname(dirname(__DIR__));
if (!file_exists($moodleroot . '/config.php')) {
    // Si no estamos dentro del plugin, intentar ruta hardcoded.
    $moodleroot = '/var/www/html/moodle/sanase';
}
require($moodleroot . '/config.php');

global $DB;

// Parsear argumento --courseid
$courseid = 67;
foreach ($argv as $arg) {
    if (strpos($arg, '--courseid=') === 0) {
        $courseid = (int)substr($arg, 11);
    }
}

echo "\n=== EXPLORACIÓN iTOP LEGACY — Curso $courseid ===\n\n";

// 1. Tablas que existen
echo "--- 1. TABLAS block_adv_reports_* EXISTENTES ---\n";
$dbman = $DB->get_manager();
$tables = [
    'block_adv_reports_times',
    'block_adv_reports_usrstats',
    'block_adv_reports_values',
    'block_adv_reports_daily',
    'block_adv_reports_sco_times',
    'block_adv_reports_videoconf',
    'block_adv_reports_chours',
    'block_adv_reports_tmethod',
    'block_adv_reports_sect_times',
    'block_adv_reports_cert',
    'block_advanced_reports',
];
foreach ($tables as $t) {
    $exists = $dbman->table_exists($t) ? 'SI' : 'NO';
    echo "  $t: $exists\n";
}

// 2. Stats en usrstats
echo "\n--- 2. STATS EN block_adv_reports_usrstats (curso $courseid) ---\n";
if ($dbman->table_exists('block_adv_reports_usrstats')) {
    $sql = "SELECT stat, COUNT(*) as total, MIN(value) as min_val, MAX(value) as max_val
            FROM {block_adv_reports_usrstats}
            WHERE courseid = ?
            GROUP BY stat
            ORDER BY stat";
    $rows = $DB->get_records_sql($sql, [$courseid]);
    if ($rows) {
        printf("  %-40s %8s %15s %15s\n", 'STAT', 'TOTAL', 'MIN', 'MAX');
        printf("  %-40s %8s %15s %15s\n", str_repeat('-', 40), '--------', '---------------', '---------------');
        foreach ($rows as $r) {
            printf("  %-40s %8d %15s %15s\n", $r->stat, $r->total, $r->min_val, $r->max_val);
        }
    } else {
        echo "  (sin datos para este curso)\n";
    }
} else {
    echo "  (tabla no existe)\n";
}

// 3. Muestra usrstats
echo "\n--- 3. MUESTRA block_adv_reports_usrstats (primeros 30) ---\n";
if ($dbman->table_exists('block_adv_reports_usrstats')) {
    $rows = $DB->get_records_sql(
        "SELECT id, userid, stat, value, dim1, timecreated FROM {block_adv_reports_usrstats} WHERE courseid = ? ORDER BY userid, stat LIMIT 30",
        [$courseid]
    );
    if ($rows) {
        printf("  %-8s %-8s %-35s %-20s %-15s %-12s\n", 'ID', 'USERID', 'STAT', 'VALUE', 'DIM1', 'TIMECREATED');
        foreach ($rows as $r) {
            printf("  %-8d %-8d %-35s %-20s %-15s %-12s\n",
                $r->id, $r->userid, $r->stat,
                substr($r->value, 0, 20),
                substr($r->dim1 ?? '', 0, 15),
                $r->timecreated ? date('Y-m-d', $r->timecreated) : ''
            );
        }
    }
}

// 4. Stats en values
echo "\n--- 4. STATS EN block_adv_reports_values (curso $courseid) ---\n";
if ($dbman->table_exists('block_adv_reports_values')) {
    $sql = "SELECT stat, reportid, COUNT(*) as total
            FROM {block_adv_reports_values}
            WHERE courseid = ?
            GROUP BY stat, reportid
            ORDER BY reportid, stat";
    $rows = $DB->get_records_sql($sql, [$courseid]);
    if ($rows) {
        printf("  %-40s %-12s %8s\n", 'STAT', 'REPORTID', 'TOTAL');
        printf("  %-40s %-12s %8s\n", str_repeat('-', 40), '------------', '--------');
        foreach ($rows as $r) {
            printf("  %-40s %-12s %8d\n", $r->stat, $r->reportid, $r->total);
        }
    } else {
        echo "  (sin datos para este curso)\n";
    }
} else {
    echo "  (tabla no existe)\n";
}

// 5. Muestra values
echo "\n--- 5. MUESTRA block_adv_reports_values (primeros 30) ---\n";
if ($dbman->table_exists('block_adv_reports_values')) {
    $rows = $DB->get_records_sql(
        "SELECT id, userid, reportid, stat, value FROM {block_adv_reports_values} WHERE courseid = ? ORDER BY userid, reportid, stat LIMIT 30",
        [$courseid]
    );
    if ($rows) {
        printf("  %-8s %-8s %-12s %-35s %-20s\n", 'ID', 'USERID', 'REPORTID', 'STAT', 'VALUE');
        foreach ($rows as $r) {
            printf("  %-8d %-8d %-12s %-35s %-20s\n",
                $r->id, $r->userid, $r->reportid, $r->stat, substr($r->value, 0, 20)
            );
        }
    }
}

// 6. Times
echo "\n--- 6. MUESTRA block_adv_reports_times (primeros 20) ---\n";
if ($dbman->table_exists('block_adv_reports_times')) {
    $rows = $DB->get_records_sql(
        "SELECT t.userid, t.dedicationtime, t.graceperiods, t.timemodified
         FROM {block_adv_reports_times} t
         WHERE t.course = ?
         ORDER BY t.userid LIMIT 20",
        [$courseid]
    );
    if ($rows) {
        printf("  %-8s %-18s %-14s %-20s\n", 'USERID', 'DEDICATIONTIME', 'GRACEPERIODS', 'TIMEMODIFIED');
        foreach ($rows as $r) {
            printf("  %-8d %-18s %-14s %-20s\n",
                $r->userid, $r->dedicationtime, $r->graceperiods ?? 0,
                $r->timemodified ? date('Y-m-d H:i', $r->timemodified) : ''
            );
        }
    }
}

// 7. chours y tmethod
echo "\n--- 7. block_adv_reports_chours (curso $courseid) ---\n";
if ($dbman->table_exists('block_adv_reports_chours')) {
    $rows = $DB->get_records('block_adv_reports_chours', ['courseid' => $courseid]);
    if ($rows) {
        foreach ($rows as $r) { print_r($r); }
    } else {
        echo "  (sin datos)\n";
    }
}

echo "\n--- 8. block_adv_reports_tmethod (curso $courseid) ---\n";
if ($dbman->table_exists('block_adv_reports_tmethod')) {
    $rows = $DB->get_records('block_adv_reports_tmethod', ['courseid' => $courseid]);
    if ($rows) {
        foreach ($rows as $r) { print_r($r); }
    } else {
        echo "  (sin datos)\n";
    }
}

// 8. Daily
echo "\n--- 9. MUESTRA block_adv_reports_daily (primeros 20) ---\n";
if ($dbman->table_exists('block_adv_reports_daily')) {
    $rows = $DB->get_records_sql(
        "SELECT userid, thedate, totalseconds, totalhits FROM {block_adv_reports_daily} WHERE courseid = ? LIMIT 20",
        [$courseid]
    );
    if ($rows) {
        printf("  %-8s %-12s %-15s %-10s\n", 'USERID', 'THEDATE', 'TOTALSECONDS', 'TOTALHITS');
        foreach ($rows as $r) {
            printf("  %-8d %-12s %-15s %-10s\n", $r->userid, $r->thedate, $r->totalseconds, $r->totalhits);
        }
    }
}

// 9. SCORM
echo "\n--- 10. MUESTRA block_adv_reports_sco_times (primeros 20) ---\n";
if ($dbman->table_exists('block_adv_reports_sco_times')) {
    $rows = $DB->get_records_sql(
        "SELECT userid, scoid, attempt, dedicationtime, timemodified FROM {block_adv_reports_sco_times} WHERE course = ? LIMIT 20",
        [$courseid]
    );
    if ($rows) {
        printf("  %-8s %-8s %-8s %-18s %-20s\n", 'USERID', 'SCOID', 'ATTEMPT', 'DEDICATIONTIME', 'TIMEMODIFIED');
        foreach ($rows as $r) {
            printf("  %-8d %-8d %-8d %-18s %-20s\n",
                $r->userid, $r->scoid, $r->attempt, $r->dedicationtime,
                $r->timemodified ? date('Y-m-d H:i', $r->timemodified) : ''
            );
        }
    } else {
        echo "  (sin datos)\n";
    }
}

echo "\n=== FIN EXPLORACIÓN ===\n";
