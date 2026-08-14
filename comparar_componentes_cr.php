<?php
/**
 * CLI para comparar el campo `components` deserializado de reportes
 * con nombre coincidente entre ambos plugins:
 *   - Propio:    block_configurable_reports
 *   - Alquilado: block_advanced_reports (u otra tabla detectada)
 *
 * Para cada curso indicado:
 *   1. Obtiene reportes de AMBOS plugins.
 *   2. Empareja por nombre (coincidencia exacta y parcial).
 *   3. Deserializa el campo `components` de cada uno con cr_unserialize.
 *   4. Compara recursivamente cada sección del componente.
 *   5. Emite un JSON detallado con las diferencias encontradas.
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=2,3,4               (obligatorio: ids separados por coma)
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

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

// Cargar locallib del plugin propio para cr_unserialize.
$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
$hasownplugin = is_readable($ownlocallib);
if ($hasownplugin) {
    require_once($ownlocallib);
}

if (!function_exists('cr_unserialize')) {
    cr_emit(['ok' => false, 'fatal' => 'cr_unserialize no disponible. ¿Está instalado block_configurable_reports?'], 2);
}

// ---------------------------------------------------------------------------
// Detectar tabla del plugin alquilado (misma lógica que comparar_reportes_cr.php)
// ---------------------------------------------------------------------------
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
                $rentedtablename = str_replace($prefix, '', $t->table_name);
                break;
            }
        }
    } catch (Throwable $e) {
        // Ignorar.
    }
}

if (empty($rentedtablename)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró la tabla del plugin alquilado'], 2);
}

// ---------------------------------------------------------------------------
// Funciones de comparación profunda
// ---------------------------------------------------------------------------

/**
 * Deserializa components de forma segura.
 */
function safe_deserialize($raw) {
    if (empty($raw)) {
        return null;
    }
    // Intentar cr_unserialize primero
    $result = cr_unserialize($raw);
    if ($result !== false && $result !== null) {
        return $result;
    }
    // Intentar unserialize nativo
    $result = @unserialize($raw);
    if ($result !== false) {
        return $result;
    }
    // Intentar base64 + unserialize
    $decoded = @base64_decode($raw, true);
    if ($decoded !== false) {
        $result = @unserialize($decoded);
        if ($result !== false) {
            return $result;
        }
    }
    // Intentar json_decode
    $result = @json_decode($raw, true);
    if ($result !== null) {
        return $result;
    }
    return null;
}

/**
 * Normaliza un valor para comparación (convierte objetos a arrays, ordena claves).
 */
function normalize_value($val) {
    if (is_object($val)) {
        $val = (array) $val;
    }
    if (is_array($val)) {
        $normalized = [];
        foreach ($val as $k => $v) {
            $normalized[$k] = normalize_value($v);
        }
        ksort($normalized);
        return $normalized;
    }
    // Normalizar tipos escalares: "0" == 0, etc.
    if (is_numeric($val)) {
        return (string) $val;
    }
    if (is_bool($val)) {
        return $val ? '1' : '0';
    }
    return $val;
}

/**
 * Compara dos estructuras recursivamente y devuelve las diferencias.
 */
function deep_compare($own, $rented, string $path = ''): array {
    $diffs = [];

    $own_n = normalize_value($own);
    $rented_n = normalize_value($rented);

    if ($own_n === $rented_n) {
        return [];
    }

    // Ambos son arrays: comparar clave por clave.
    if (is_array($own_n) && is_array($rented_n)) {
        $allkeys = array_unique(array_merge(array_keys($own_n), array_keys($rented_n)));
        sort($allkeys);
        foreach ($allkeys as $key) {
            $subpath = $path ? "{$path}.{$key}" : (string) $key;
            $own_has = array_key_exists($key, $own_n);
            $rented_has = array_key_exists($key, $rented_n);

            if ($own_has && !$rented_has) {
                $diffs[] = [
                    'path' => $subpath,
                    'type' => 'solo_en_propio',
                    'own_value' => summarize_value($own_n[$key]),
                ];
            } else if (!$own_has && $rented_has) {
                $diffs[] = [
                    'path' => $subpath,
                    'type' => 'solo_en_alquilado',
                    'rented_value' => summarize_value($rented_n[$key]),
                ];
            } else {
                $subdiffs = deep_compare($own_n[$key], $rented_n[$key], $subpath);
                $diffs = array_merge($diffs, $subdiffs);
            }
        }
        return $diffs;
    }

    // Tipos diferentes o valores escalares distintos.
    $diffs[] = [
        'path' => $path ?: '(raíz)',
        'type' => 'valor_diferente',
        'own_value' => summarize_value($own_n),
        'rented_value' => summarize_value($rented_n),
    ];
    return $diffs;
}

/**
 * Resume un valor para el reporte (trunca strings largos).
 */
function summarize_value($val): string {
    if (is_null($val)) {
        return '(null)';
    }
    if (is_bool($val)) {
        return $val ? 'true' : 'false';
    }
    if (is_array($val)) {
        $json = json_encode($val, JSON_UNESCAPED_UNICODE);
        return strlen($json) > 120 ? substr($json, 0, 117) . '...' : $json;
    }
    $str = (string) $val;
    return strlen($str) > 120 ? substr($str, 0, 117) . '...' : $str;
}

/**
 * Intenta emparejar un nombre del propio con uno del alquilado.
 * Primero intenta coincidencia exacta, luego parcial.
 */
function match_report_name(string $own_name, array $rented_names): ?string {
    // Exacta
    if (in_array($own_name, $rented_names, true)) {
        return $own_name;
    }
    // Normalizada (sin espacios extra, lowercase)
    $own_norm = strtolower(trim(preg_replace('/\s+/', ' ', $own_name)));
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if ($own_norm === $rn_norm) {
            return $rn;
        }
    }
    // Coincidencia parcial: el nombre propio contiene al alquilado o viceversa
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if (strpos($own_norm, $rn_norm) !== false || strpos($rn_norm, $own_norm) !== false) {
            return $rn;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Comparar por curso
// ---------------------------------------------------------------------------
$output = [];
$summary = [
    'total_courses' => 0,
    'total_pairs' => 0,
    'identical_pairs' => 0,
    'different_pairs' => 0,
    'own_only' => 0,
    'rented_only' => 0,
    'deserialize_errors' => 0,
];

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) {
        $output[] = ['courseid' => $courseid, 'error' => 'Curso no encontrado'];
        continue;
    }

    $summary['total_courses']++;
    $coursedata = [
        'courseid' => $courseid,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'pairs' => [],
        'own_only' => [],
        'rented_only' => [],
    ];

    // Obtener reportes del propio.
    $ownreports = [];
    if ($dbman->table_exists('block_configurable_reports')) {
        $ownreports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
    }

    // Obtener reportes del alquilado.
    $rentedreports = [];
    if ($dbman->table_exists($rentedtablename)) {
        // Detectar campo de curso.
        $samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
        $columns_info = $samplerow ? array_keys((array) reset($samplerow)) : [];
        $coursefield = in_array('courseid', $columns_info) ? 'courseid' : null;
        if (!$coursefield && in_array('course', $columns_info)) {
            $coursefield = 'course';
        }
        if ($coursefield) {
            $rentedreports = $DB->get_records($rentedtablename, [$coursefield => $courseid], 'id ASC');
        }
    }

    // Indexar por nombre.
    $own_by_name = [];
    foreach ($ownreports as $r) {
        $own_by_name[$r->name] = $r;
    }
    $rented_by_name = [];
    foreach ($rentedreports as $r) {
        $name = $r->name ?? $r->title ?? $r->reportname ?? "id_{$r->id}";
        $rented_by_name[$name] = $r;
    }

    // Emparejar y comparar.
    $matched_rented = [];
    foreach ($own_by_name as $own_name => $own_rep) {
        $rented_match = match_report_name($own_name, array_keys($rented_by_name));

        if ($rented_match === null) {
            $coursedata['own_only'][] = [
                'id' => (int) $own_rep->id,
                'name' => $own_name,
                'type' => $own_rep->type ?? null,
            ];
            $summary['own_only']++;
            continue;
        }

        $matched_rented[] = $rented_match;
        $rented_rep = $rented_by_name[$rented_match];
        $summary['total_pairs']++;

        // Deserializar ambos.
        $own_components = safe_deserialize($own_rep->components);
        $rented_components = safe_deserialize($rented_rep->components);

        $pair = [
            'own_id' => (int) $own_rep->id,
            'rented_id' => (int) $rented_rep->id,
            'own_name' => $own_name,
            'rented_name' => $rented_match,
            'own_type' => $own_rep->type ?? null,
            'rented_type' => $rented_rep->type ?? null,
        ];

        if ($own_components === null && $rented_components === null) {
            $pair['status'] = 'ambos_sin_componentes';
            $pair['identical'] = true;
            $summary['identical_pairs']++;
        } else if ($own_components === null) {
            $pair['status'] = 'error_deserializar_propio';
            $pair['identical'] = false;
            $summary['deserialize_errors']++;
            $summary['different_pairs']++;
        } else if ($rented_components === null) {
            $pair['status'] = 'error_deserializar_alquilado';
            $pair['identical'] = false;
            $summary['deserialize_errors']++;
            $summary['different_pairs']++;
        } else {
            // Comparación profunda.
            $diffs = deep_compare($own_components, $rented_components);
            if (empty($diffs)) {
                $pair['status'] = 'idénticos';
                $pair['identical'] = true;
                $summary['identical_pairs']++;
            } else {
                $pair['status'] = 'diferentes';
                $pair['identical'] = false;
                $pair['diff_count'] = count($diffs);
                $pair['diffs'] = $diffs;
                $summary['different_pairs']++;
            }
        }

        // Comparar también campos de metadatos relevantes.
        $meta_diffs = [];
        if (isset($own_rep->type) && isset($rented_rep->type) && $own_rep->type !== $rented_rep->type) {
            $meta_diffs[] = ['field' => 'type', 'own' => $own_rep->type, 'rented' => $rented_rep->type];
        }
        if (isset($own_rep->pagination) && isset($rented_rep->pagination) && (string)$own_rep->pagination !== (string)$rented_rep->pagination) {
            $meta_diffs[] = ['field' => 'pagination', 'own' => $own_rep->pagination, 'rented' => $rented_rep->pagination];
        }
        if (isset($own_rep->export) && isset($rented_rep->export) && $own_rep->export !== $rented_rep->export) {
            $meta_diffs[] = ['field' => 'export', 'own' => $own_rep->export, 'rented' => $rented_rep->export];
        }
        if (isset($own_rep->jsordering) && isset($rented_rep->jsordering) && (string)$own_rep->jsordering !== (string)$rented_rep->jsordering) {
            $meta_diffs[] = ['field' => 'jsordering', 'own' => $own_rep->jsordering, 'rented' => $rented_rep->jsordering];
        }
        if (!empty($meta_diffs)) {
            $pair['meta_diffs'] = $meta_diffs;
        }

        $coursedata['pairs'][] = $pair;
    }

    // Reportes solo en el alquilado (sin pareja en el propio).
    foreach ($rented_by_name as $rname => $rrep) {
        if (!in_array($rname, $matched_rented, true)) {
            $coursedata['rented_only'][] = [
                'id' => (int) $rrep->id,
                'name' => $rname,
                'type' => $rrep->type ?? null,
            ];
            $summary['rented_only']++;
        }
    }

    $output[] = $coursedata;
}

cr_emit([
    'ok' => true,
    'rented_table' => $rentedtablename,
    'summary' => $summary,
    'courses' => $output,
], 0);
