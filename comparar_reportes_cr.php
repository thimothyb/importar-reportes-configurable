<?php
/**
 * CLI para COMPARAR los datos que generan ambos plugins de reportes:
 *   - Propio:    block_configurable_reports
 *   - Alquilado: block_advanced_reports
 *
 * Para cada curso indicado:
 *   1. Lista los reportes de AMBOS plugins (configuración: nombre, tipo, SQL).
 *   2. Ejecuta cada reporte SQL y captura las primeras N filas del resultado.
 *   3. Emite todo como JSON entre marcadores <<<CR_RESULT>>>...<<<END_CR_RESULT>>>
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=2,3,4               (obligatorio: ids separados por coma)
 *   --maxrows=20                  (opcional: máx filas por reporte, def 50)
 *
 * @package   block_configurable_reports
 * @copyright 2026 Awakelab
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) {
        $cliargs[$m[1]] = $m[2];
    } else if (preg_match('/^--([^=]+)$/', $token, $m)) {
        $cliargs[$m[1]] = true;
    }
}

function cr_emit(array $payload, int $exitcode): void {
    fwrite(STDOUT, "<<<CR_RESULT>>>" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con la ruta a config.php válida'], 2);
}
if (empty($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses (ids separados por coma)'], 2);
}

$courseids = array_filter(array_map('intval', explode(',', $cliargs['courses'])));
$maxrows = isset($cliargs['maxrows']) ? (int) $cliargs['maxrows'] : 50;

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

// Cargar locallib del plugin propio si existe (para cr_unserialize).
$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
$hasownplugin = is_readable($ownlocallib);
if ($hasownplugin) {
    require_once($ownlocallib);
}

/**
 * Extrae la consulta SQL de un reporte de configurable_reports.
 */
function extract_own_sql($report): ?string {
    if (!function_exists('cr_unserialize')) {
        return null;
    }
    $components = cr_unserialize($report->components);
    if (is_array($components) && isset($components['customsql']['config']->querysql)) {
        return $components['customsql']['config']->querysql;
    }
    return null;
}

/**
 * Intenta extraer la consulta SQL de un reporte del plugin alquilado.
 * El esquema puede variar; probamos campos comunes.
 */
function extract_rented_sql($report): ?string {
    // Intentar con campo 'components' si existe
    if (!empty($report->components)) {
        // Intentar deserializar con cr_unserialize si está disponible
        if (function_exists('cr_unserialize')) {
            $components = cr_unserialize($report->components);
            if (is_array($components) && isset($components['customsql']['config']->querysql)) {
                return $components['customsql']['config']->querysql;
            }
        }
        // Intentar unserialize nativo de PHP
        $components = @unserialize($report->components);
        if (is_array($components) && isset($components['customsql']['config']->querysql)) {
            return $components['customsql']['config']->querysql;
        }
        // Intentar base64 + unserialize
        $decoded = @base64_decode($report->components, true);
        if ($decoded !== false) {
            $components = @unserialize($decoded);
            if (is_array($components) && isset($components['customsql']['config']->querysql)) {
                return $components['customsql']['config']->querysql;
            }
        }
    }
    // Intentar con campo 'querysql' directo
    if (!empty($report->querysql)) {
        return $report->querysql;
    }
    // Intentar con campo 'configdata'
    if (!empty($report->configdata)) {
        $config = @json_decode($report->configdata);
        if ($config && !empty($config->querysql)) {
            return $config->querysql;
        }
        $config = @unserialize($report->configdata);
        if (is_array($config) && !empty($config['querysql'])) {
            return $config['querysql'];
        }
    }
    return null;
}

/**
 * Ejecuta una consulta SQL de solo lectura y devuelve hasta $maxrows filas.
 */
function execute_report_sql(string $sql, int $courseid, int $maxrows): array {
    global $DB;

    // Reemplazar placeholders comunes del plugin
    $sql = str_replace(['%%COURSEID%%', '%%USERID%%', '%%CATEGORYID%%'], [$courseid, 0, 0], $sql);

    // Seguridad: solo permitir SELECT
    $trimmed = ltrim($sql);
    if (!preg_match('/^\s*SELECT\b/i', $trimmed)) {
        return ['error' => 'No es una consulta SELECT', 'rows' => [], 'columns' => []];
    }

    try {
        $rs = $DB->get_recordset_sql($sql, [], 0, $maxrows);
        $rows = [];
        $columns = [];
        foreach ($rs as $record) {
            $row = (array) $record;
            if (empty($columns)) {
                $columns = array_keys($row);
            }
            $rows[] = $row;
        }
        $rs->close();
        return ['error' => null, 'rows' => $rows, 'columns' => $columns, 'row_count' => count($rows)];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'rows' => [], 'columns' => []];
    }
}

// ---------------------------------------------------------------------------
// Detectar tablas del plugin alquilado
// ---------------------------------------------------------------------------
$dbman = $DB->get_manager();
$rentedtable = null;
$rentedtablename = '';

// Probar nombres de tabla comunes para block_advanced_reports
$candidates = ['block_advanced_reports', 'block_adv_reports', 'block_advreports'];
foreach ($candidates as $tname) {
    if ($dbman->table_exists($tname)) {
        $rentedtablename = $tname;
        break;
    }
}

// Si no encontramos tabla específica, listar todas las tablas que contengan 'advanced' o 'adv_report'
if (empty($rentedtablename)) {
    // Buscar en information_schema
    try {
        $prefix = $CFG->prefix;
        $alltables = $DB->get_records_sql(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?",
            ["{$prefix}%adv%report%"]
        );
        if ($alltables) {
            foreach ($alltables as $t) {
                $name = str_replace($prefix, '', $t->table_name);
                $rentedtablename = $name;
                break; // Tomar la primera
            }
        }
    } catch (Throwable $e) {
        // Ignorar errores de búsqueda
    }
}

// ---------------------------------------------------------------------------
// Recopilar datos por curso
// ---------------------------------------------------------------------------
$output = [];

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) {
        $output[] = [
            'courseid' => $courseid,
            'error' => 'Curso no encontrado',
        ];
        continue;
    }

    $coursedata = [
        'courseid' => $courseid,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'own_reports' => [],
        'rented_reports' => [],
        'rented_table' => $rentedtablename ?: null,
    ];

    // --- Reportes del plugin PROPIO ---
    if ($dbman->table_exists('block_configurable_reports')) {
        $ownreports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
        foreach ($ownreports as $rep) {
            $sql = extract_own_sql($rep);
            $result = null;
            if ($sql) {
                $result = execute_report_sql($sql, $courseid, $maxrows);
            }
            $coursedata['own_reports'][] = [
                'id' => (int) $rep->id,
                'name' => $rep->name,
                'type' => $rep->type ?? null,
                'sql' => $sql,
                'data' => $result,
            ];
        }
    }

    // --- Reportes del plugin ALQUILADO ---
    if (!empty($rentedtablename) && $dbman->table_exists($rentedtablename)) {
        // Primero, ver qué columnas tiene la tabla
        $columns_info = [];
        try {
            $samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
            if ($samplerow) {
                $columns_info = array_keys((array) reset($samplerow));
            }
        } catch (Throwable $e) {
            $coursedata['rented_table_error'] = $e->getMessage();
        }
        $coursedata['rented_table_columns'] = $columns_info;

        // Buscar reportes del curso
        $coursefield = in_array('courseid', $columns_info) ? 'courseid' : null;
        if (!$coursefield && in_array('course', $columns_info)) {
            $coursefield = 'course';
        }

        if ($coursefield) {
            try {
                $rentedreports = $DB->get_records($rentedtablename, [$coursefield => $courseid], 'id ASC');
                foreach ($rentedreports as $rep) {
                    $sql = extract_rented_sql($rep);
                    $result = null;
                    if ($sql) {
                        $result = execute_report_sql($sql, $courseid, $maxrows);
                    }

                    $name = $rep->name ?? $rep->title ?? $rep->reportname ?? "id_{$rep->id}";
                    $type = $rep->type ?? $rep->reporttype ?? null;

                    $coursedata['rented_reports'][] = [
                        'id' => (int) $rep->id,
                        'name' => $name,
                        'type' => $type,
                        'sql' => $sql,
                        'data' => $result,
                        'raw_fields' => array_keys((array) $rep),
                    ];
                }
            } catch (Throwable $e) {
                $coursedata['rented_query_error'] = $e->getMessage();
            }
        } else {
            $coursedata['rented_note'] = "La tabla {$rentedtablename} no tiene campo courseid/course";
        }
    }

    $output[] = $coursedata;
}

cr_emit([
    'ok' => true,
    'rented_table_found' => $rentedtablename ?: null,
    'own_plugin_present' => $hasownplugin,
    'courses' => $output,
], 0);
