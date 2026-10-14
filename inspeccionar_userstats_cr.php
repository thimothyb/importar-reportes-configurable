<?php
/**
 * CLI para inspeccionar la estructura completa de las columnas userstats (iTOP)
 * y userstatsadvanced (nuestro plugin) para entender qué campos almacenan
 * items seleccionados y poder construir la migración.
 *
 * Extrae TODOS los campos del formdata de cada columna userstats/userstatsadvanced
 * de ambos plugins, para poder ver exactamente qué se guarda cuando hay items seleccionados.
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=all|2,3,4           (obligatorio)
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
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $payload = json_decode(json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? $payload;
        $json = json_encode($payload, $flags);
    }
    if ($json === false) {
        $json = json_encode(['ok' => false, 'fatal' => 'json_encode falló: ' . json_last_error_msg()], $flags);
    }
    fwrite(STDOUT, "<<<CR_RESULT>>>" . $json . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con ruta a config.php válida'], 2);
}
if (empty($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses (ids separados por coma o "all")'], 2);
}

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) {
    require_once($ownlocallib);
}
if (!function_exists('cr_unserialize')) {
    cr_emit(['ok' => false, 'fatal' => 'cr_unserialize no disponible'], 2);
}

// Detectar tabla del plugin alquilado (iTOP).
$dbman = $DB->get_manager();
$rentedtablename = '';
$candidates = ['block_advanced_reports', 'block_adv_reports', 'block_advreports'];
foreach ($candidates as $tname) {
    if ($dbman->table_exists($tname)) {
        $rentedtablename = $tname;
        break;
    }
}
if (empty($rentedtablename)) {
    try {
        $prefix = $CFG->prefix;
        $alltables = $DB->get_records_sql(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?",
            ["{$prefix}%adv%report%"]
        );
        if ($alltables) {
            foreach ($alltables as $t) {
                $raw = str_replace($prefix, '', $t->table_name);
                if (strpos($raw, '_times') === false && strpos($raw, '_usrstats') === false
                    && strpos($raw, '_values') === false && strpos($raw, '_daily') === false
                    && strpos($raw, '_sco_') === false && strpos($raw, '_videoconf') === false
                    && strpos($raw, '_chours') === false && strpos($raw, '_tmethod') === false
                    && strpos($raw, '_sect_') === false && strpos($raw, '_cert') === false) {
                    $rentedtablename = $raw;
                    break;
                }
            }
        }
    } catch (Throwable $e) { /* ignorar */ }
}
if (empty($rentedtablename)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró tabla del plugin iTOP'], 2);
}

// Función de deserialización segura.
function safe_deserialize($raw) {
    if (empty($raw)) return null;
    $result = cr_unserialize($raw);
    if ($result !== false && $result !== null) return $result;
    $result = @unserialize($raw);
    if ($result !== false) return $result;
    $decoded = @base64_decode($raw, true);
    if ($decoded !== false) {
        $result = @unserialize($decoded);
        if ($result !== false) return $result;
    }
    $result = @json_decode($raw, true);
    if ($result !== null) return $result;
    return null;
}

/**
 * Convierte recursivamente objetos stdClass a arrays asociativos
 * para que la salida JSON sea legible y uniforme.
 */
function deep_to_array($val) {
    if (is_object($val)) $val = (array) $val;
    if (is_array($val)) {
        foreach ($val as $k => $v) {
            $val[$k] = deep_to_array($v);
        }
    }
    return $val;
}

/**
 * Extrae TODAS las columnas de un pluginname dado, con su formdata completo.
 */
function extract_all_columns($components, string $pluginname): array {
    $columns = [];
    if (!is_array($components) && !is_object($components)) return [];
    $comp = is_object($components) ? (array) $components : $components;

    $cols = $comp['columns'] ?? $comp['Columns'] ?? null;
    if (!$cols) return [];
    $cols = is_object($cols) ? (array) $cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array) $elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array) $el : $el;
        $pname = $el['pluginname'] ?? $el['id'] ?? '';
        if ($pname === $pluginname) {
            $columns[] = [
                'element_index' => $idx,
                'pluginname' => $pname,
                'pluginfullname' => $el['pluginfullname'] ?? '',
                'summary' => $el['summary'] ?? '',
                'formdata' => deep_to_array($el['formdata'] ?? $el),
                'raw_element' => deep_to_array($el),
            ];
        }
    }
    return $columns;
}

function strip_report_prefix(string $name): string {
    $stripped = preg_replace('/^\d+[\-\s]+/', '', $name);
    return strtolower(trim(preg_replace('/\s+/', ' ', $stripped)));
}

function match_report_name(string $own_name, array $rented_names): ?string {
    if (in_array($own_name, $rented_names, true)) return $own_name;
    $own_norm = strtolower(trim(preg_replace('/\s+/', ' ', $own_name)));
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if ($own_norm === $rn_norm) return $rn;
    }
    $own_stripped = strip_report_prefix($own_name);
    foreach ($rented_names as $rn) {
        $rn_stripped = strip_report_prefix($rn);
        if ($own_stripped === $rn_stripped) return $rn;
    }
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if (strpos($own_norm, $rn_norm) !== false || strpos($rn_norm, $own_norm) !== false) {
            return $rn;
        }
    }
    return null;
}

// Determinar cursos.
$samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
$columns_info = $samplerow ? array_keys((array) reset($samplerow)) : [];
$rcoursefield = in_array('courseid', $columns_info) ? 'courseid' : (in_array('course', $columns_info) ? 'course' : null);

if ($cliargs['courses'] === 'all') {
    $owncourses = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {block_configurable_reports}");
    $rentedcourses = $rcoursefield
        ? $DB->get_fieldset_sql("SELECT DISTINCT {$rcoursefield} FROM {{$rentedtablename}}")
        : [];
    $courseids = array_values(array_intersect(array_map('intval', $owncourses), array_map('intval', $rentedcourses)));
    sort($courseids);
} else {
    $courseids = array_filter(array_map('intval', explode(',', $cliargs['courses'])));
}

if (empty($courseids)) {
    cr_emit(['ok' => true, 'warning' => 'No hay cursos', 'courses' => []], 0);
}

// Procesar.
$result = [];
foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) continue;

    $ownreports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
    $rentedreports = $rcoursefield
        ? $DB->get_records($rentedtablename, [$rcoursefield => $courseid], 'id ASC')
        : [];

    if (empty($ownreports) || empty($rentedreports)) continue;

    $own_by_name = [];
    foreach ($ownreports as $r) $own_by_name[$r->name] = $r;
    $rented_by_name = [];
    foreach ($rentedreports as $r) {
        $name = $r->name ?? $r->title ?? "id_{$r->id}";
        $rented_by_name[$name] = $r;
    }

    $coursedata = [
        'courseid' => $courseid,
        'fullname' => $course->fullname,
        'reports' => [],
    ];

    foreach ($own_by_name as $own_name => $own_rep) {
        $rented_match = match_report_name($own_name, array_keys($rented_by_name));
        if ($rented_match === null) continue;
        $rented_rep = $rented_by_name[$rented_match];

        $own_comp = safe_deserialize($own_rep->components);
        $rented_comp = safe_deserialize($rented_rep->components);
        if (!$own_comp || !$rented_comp) continue;

        // Extraer columnas userstatsadvanced (nuestro) y userstats (iTOP).
        $own_usa_cols = extract_all_columns($own_comp, 'userstatsadvanced');
        $itop_us_cols = extract_all_columns($rented_comp, 'userstats');

        // Si alguno de los dos tiene columnas de este tipo, reportar.
        if (empty($own_usa_cols) && empty($itop_us_cols)) continue;

        $reportdata = [
            'own_report' => ['id' => (int)$own_rep->id, 'name' => $own_name],
            'itop_report' => ['id' => (int)$rented_rep->id, 'name' => $rented_match],
            'own_userstatsadvanced_columns' => $own_usa_cols,
            'itop_userstats_columns' => $itop_us_cols,
        ];

        $coursedata['reports'][] = $reportdata;
    }

    if (!empty($coursedata['reports'])) {
        $result[] = $coursedata;
    }
}

cr_emit([
    'ok' => true,
    'rented_table' => $rentedtablename,
    'total_courses' => count($result),
    'courses' => $result,
], 0);
