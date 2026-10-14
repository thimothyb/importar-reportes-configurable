<?php
/**
 * CLI para migrar las configuraciones de columnas (stat) desde el plugin iTOP
 * (block_advanced_reports / modulestats) hacia nuestro plugin propio
 * (block_configurable_reports / scormadvancedgrades).
 *
 * Para cada curso indicado:
 *   1. Obtiene reportes de AMBOS plugins.
 *   2. Empareja por nombre (coincidencia exacta y parcial).
 *   3. Deserializa el campo `components` de cada uno.
 *   4. Busca columnas modulestats en iTOP y scormadvancedgrades en el propio.
 *   5. Empareja columnas por nombre de cabecera.
 *   6. Extrae la referencia stat real de iTOP (con cmid correcto).
 *   7. Construye el stat en formato propio (modname:instanceid:metric).
 *   8. Actualiza el campo components serializado en BD (modo --apply).
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=all|2,3,4           (obligatorio: "all" o ids separados por coma)
 *   --apply                       (opcional: sin este flag solo hace dry-run)
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
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con la ruta a config.php válida'], 2);
}
if (empty($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses (ids separados por coma o "all")'], 2);
}

$applymode = !empty($cliargs['apply']);

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

// Cargar locallib para urldecode_recursive/urlencode_recursive.
$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) {
    require_once($ownlocallib);
}
if (!function_exists('urldecode_recursive')) {
    cr_emit(['ok' => false, 'fatal' => 'urldecode_recursive no disponible. ¿Está instalado block_configurable_reports?'], 2);
}

// ---------------------------------------------------------------------------
// Detectar tabla del plugin alquilado
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
                $raw = str_replace($prefix, '', $t->table_name);
                // Buscar la tabla principal de reportes (no las de datos auxiliares).
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
    } catch (Throwable $e) {
        // Ignorar.
    }
}

if (empty($rentedtablename)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró la tabla del plugin alquilado (iTOP)'], 2);
}

if (!$dbman->table_exists('block_configurable_reports')) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró la tabla block_configurable_reports'], 2);
}

// ---------------------------------------------------------------------------
// Determinar cursos a procesar
// ---------------------------------------------------------------------------
if ($cliargs['courses'] === 'all') {
    // Todos los cursos que tengan reportes en ambos plugins.
    $owncourses = $DB->get_fieldset_sql("SELECT DISTINCT courseid FROM {block_configurable_reports}");
    // Detectar campo de curso del plugin alquilado.
    $samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
    $columns_info = $samplerow ? array_keys((array) reset($samplerow)) : [];
    $rcoursefield = in_array('courseid', $columns_info) ? 'courseid' : (in_array('course', $columns_info) ? 'course' : null);
    if ($rcoursefield) {
        $rentedcourses = $DB->get_fieldset_sql("SELECT DISTINCT {$rcoursefield} FROM {{$rentedtablename}}");
    } else {
        $rentedcourses = [];
    }
    $courseids = array_values(array_intersect(array_map('intval', $owncourses), array_map('intval', $rentedcourses)));
    sort($courseids);
} else {
    $courseids = array_filter(array_map('intval', explode(',', $cliargs['courses'])));
}

if (empty($courseids)) {
    cr_emit(['ok' => true, 'warning' => 'No hay cursos con ambos plugins', 'courses' => [], 'summary' => []], 0);
}

// ---------------------------------------------------------------------------
// Funciones auxiliares
// ---------------------------------------------------------------------------

/**
 * Decode components preserving exact PHP types (stdClass stays stdClass).
 * Unlike cr_unserialize, this does NOT do (array) cast on the top level.
 */
function raw_deserialize(string $raw) {
    $raw = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $raw = preg_replace('/THEN\+%22(.+?)%22/', 'THEN+%27${1}%27', $raw);
    $raw = preg_replace('/%60/', '+++', $raw);
    $data = @unserialize($raw);
    if ($data === false && $raw !== serialize(false)) {
        return null;
    }
    return urldecode_recursive($data);
}

/**
 * Encode components back preserving types (no (array) cast).
 */
function raw_serialize($data): string {
    return serialize(urlencode_recursive($data));
}

/**
 * Normalize component structure: sections and elements as arrays, formdata as stdClass.
 */
function normalize_components($data) {
    $data = (array) $data;
    $sections = ['columns', 'conditions', 'permissions', 'filters', 'ordering', 'calcs', 'plot', 'customsql'];
    foreach ($sections as $sec) {
        if (!isset($data[$sec])) continue;
        $data[$sec] = (array) $data[$sec];
        if (isset($data[$sec]['elements'])) {
            $data[$sec]['elements'] = array_values((array) $data[$sec]['elements']);
            foreach ($data[$sec]['elements'] as $i => $el) {
                $data[$sec]['elements'][$i] = (array) $el;
            }
        }
    }
    return $data;
}


/**
 * Deserializa components de forma segura.
 */
function safe_deserialize($raw) {
    if (empty($raw)) return null;
    // Use raw_deserialize to preserve exact PHP types (NO (array) cast).
    $result = raw_deserialize($raw);
    if ($result !== null) return $result;
    // Fallback attempts.
    $decoded = @base64_decode($raw, true);
    if ($decoded !== false) {
        $result = raw_deserialize($decoded);
        if ($result !== null) return $result;
    }
    return null;
}

/**
 * Extrae columnas de un tipo de plugin específico del array de components.
 * components normalmente tiene estructura:
 *   ['columns' => ['elements' => [ {pluginname, formdata, ...}, ... ]]]
 */
function extract_columns($components, string $pluginname): array {
    $columns = [];
    if (!is_array($components) && !is_object($components)) return [];
    $comp = is_object($components) ? (array) $components : $components;

    // Buscar en 'columns' -> 'elements'.
    $cols = $comp['columns'] ?? $comp['Columns'] ?? null;
    if ($cols) {
        $cols = is_object($cols) ? (array) $cols : $cols;
        $elements = $cols['elements'] ?? [];
        if (is_object($elements)) $elements = (array) $elements;
        foreach ($elements as $idx => $el) {
            $el = is_object($el) ? (array) $el : $el;
            $pname = $el['pluginname'] ?? $el['id'] ?? '';
            if ($pname === $pluginname) {
                $el['_index'] = $idx;
                $columns[] = $el;
            }
        }
    }
    return $columns;
}

/**
 * Obtiene el nombre de cabecera (header) de una columna.
 */
function get_column_header($col): string {
    $col = is_object($col) ? (array) $col : $col;
    $fd = $col['formdata'] ?? $col;
    $fd = is_object($fd) ? (array) $fd : $fd;
    // Buscar en distintos campos posibles.
    return trim(
        $fd['columname'] ?? $fd['column_name'] ?? $fd['columnname'] ??
        $fd['header'] ?? $fd['name'] ?? $fd['titulo'] ?? ''
    );
}

/**
 * Obtiene el valor stat de una columna scormadvancedgrades.
 */
function get_column_stat($col): string {
    $col = is_object($col) ? (array) $col : $col;
    $fd = $col['formdata'] ?? $col;
    $fd = is_object($fd) ? (array) $fd : $fd;
    return trim($fd['stat'] ?? $fd['estadistica'] ?? '');
}

/**
 * Obtiene la referencia stat/config de una columna modulestats de iTOP.
 * iTOP almacena la referencia al módulo en distintos campos posibles.
 */
function get_itop_stat_reference($col): array {
    $col = is_object($col) ? (array) $col : $col;
    $fd = $col['formdata'] ?? $col;
    $fd = is_object($fd) ? (array) $fd : $fd;

    $info = [
        'stat' => '',
        'module_type' => '',
        'cmid' => 0,
        'instance_id' => 0,
        'metric' => '',
        'raw_stat' => '',
    ];

    // iTOP guarda el stat de varias formas posibles.
    $stat = $fd['stat'] ?? $fd['estadistica'] ?? $fd['statistic'] ?? '';
    $info['raw_stat'] = $stat;

    // Formato típico de iTOP: "quiz_score_id3577" o "assign_submissiondate_id6848"
    // O también: "quiz:3577:score" (ya convertido).
    if (preg_match('/^(\w+)_(\w+)_id(\d+)$/i', $stat, $m)) {
        $info['module_type'] = strtolower($m[1]);
        $info['metric'] = strtolower($m[2]);
        $info['cmid'] = (int) $m[3];
        $info['stat'] = $info['module_type'] . ':' . $info['cmid'] . ':' . $info['metric'];
    } else if (preg_match('/^(\w+):(\d+):(\w+)$/i', $stat, $m)) {
        $info['module_type'] = strtolower($m[1]);
        $info['cmid'] = (int) $m[2];
        $info['metric'] = strtolower($m[3]);
        $info['stat'] = $stat;
    } else {
        // Intentar otros campos de iTOP.
        $moduletype = $fd['moduletype'] ?? $fd['module_type'] ?? $fd['modtype'] ?? '';
        $cmid = $fd['cmid'] ?? $fd['coursemoduleid'] ?? $fd['moduleid'] ?? 0;
        $metric = $fd['metric'] ?? $fd['measure'] ?? '';
        if ($moduletype && $cmid) {
            $info['module_type'] = strtolower($moduletype);
            $info['cmid'] = (int) $cmid;
            $info['metric'] = strtolower($metric ?: 'score');
            $info['stat'] = $info['module_type'] . ':' . $info['cmid'] . ':' . $info['metric'];
        } else {
            // Último intento: extraer de "column" o "reportcolumn" fields.
            $colref = $fd['column'] ?? $fd['reportcolumn'] ?? '';
            if (preg_match('/^(\w+)_(\w+)_id(\d+)$/i', $colref, $m2)) {
                $info['module_type'] = strtolower($m2[1]);
                $info['metric'] = strtolower($m2[2]);
                $info['cmid'] = (int) $m2[3];
                $info['stat'] = $info['module_type'] . ':' . $info['cmid'] . ':' . $info['metric'];
            }
        }
    }

    // El ID del formato iTOP (quiz_score_id3577) es el INSTANCE ID
    // (ej: quiz.id = 3577), NO el course_modules.id (cmid).
    // Nuestro plugin usa formato modname:cmid:metric, necesitamos el cmid real.
    if ($info['cmid'] > 0 && $info['module_type'] !== '') {
        global $DB;
        $instance_id = $info['cmid'];
        $info['instance_id'] = $instance_id;

        try {
            $mod = $DB->get_record('modules', ['name' => $info['module_type']], 'id');
            if ($mod) {
                $cm = $DB->get_record('course_modules', [
                    'module' => $mod->id,
                    'instance' => $instance_id,
                ], 'id');
                if ($cm) {
                    $info['cmid'] = (int) $cm->id;
                    $info['stat'] = $info['module_type'] . ':' . $info['cmid'] . ':' . $info['metric'];
                } else {
                    $info['stat'] = $info['module_type'] . ':' . $instance_id . ':' . $info['metric'];
                }
            }
        } catch (Throwable $e) {
            $info['stat'] = $info['module_type'] . ':' . $instance_id . ':' . $info['metric'];
        }
    }

    return $info;
}

/**
 * Normaliza un nombre de cabecera para matching flexible.
 */
function normalize_header(string $header): string {
    $h = mb_strtolower(trim($header));
    $h = preg_replace('/\s+/', ' ', $h);
    // Quitar acentos básicos.
    $h = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
        $h
    );
    return $h;
}

/**
 * Empareja columnas por nombre de cabecera.
 */
function match_columns_by_header(array $own_cols, array $itop_cols): array {
    $pairs = [];
    $matched_itop = [];

    foreach ($own_cols as $own) {
        $own_header = get_column_header($own);
        $own_norm = normalize_header($own_header);
        $best_match = null;
        $best_score = 0;

        foreach ($itop_cols as $idx => $itop) {
            if (in_array($idx, $matched_itop)) continue;
            $itop_header = get_column_header($itop);
            $itop_norm = normalize_header($itop_header);

            // Coincidencia exacta normalizada.
            if ($own_norm === $itop_norm && $own_norm !== '') {
                $best_match = $idx;
                $best_score = 100;
                break;
            }
            // Contención.
            if ($own_norm !== '' && $itop_norm !== '') {
                if (strpos($own_norm, $itop_norm) !== false || strpos($itop_norm, $own_norm) !== false) {
                    $score = 80;
                    if ($score > $best_score) {
                        $best_match = $idx;
                        $best_score = $score;
                    }
                }
            }
        }

        if ($best_match !== null) {
            $matched_itop[] = $best_match;
            $pairs[] = [
                'own' => $own,
                'itop' => $itop_cols[$best_match],
                'own_header' => $own_header,
                'itop_header' => get_column_header($itop_cols[$best_match]),
                'match_score' => $best_score,
            ];
        }
    }
    return $pairs;
}

/**
 * Empareja columnas por posición cuando el matching por nombre no es suficiente.
 * Usa la posición ordinal de las columnas del mismo plugin.
 */
function match_columns_by_position(array $own_cols, array $itop_cols): array {
    $pairs = [];
    $count = min(count($own_cols), count($itop_cols));
    for ($i = 0; $i < $count; $i++) {
        $pairs[] = [
            'own' => $own_cols[$i],
            'itop' => $itop_cols[$i],
            'own_header' => get_column_header($own_cols[$i]),
            'itop_header' => get_column_header($itop_cols[$i]),
            'match_score' => 50, // posicional.
        ];
    }
    return $pairs;
}

/**
 * Intenta emparejar un nombre del propio con uno del alquilado.
 */
/**
 * Quita el prefijo numérico de un nombre de reporte.
 * Ej: "001-Informe Global" → "informe global"
 *     "01 Informe Global"  → "informe global"
 *     "005-Detalle Evaluaciones" → "detalle evaluaciones"
 */
function strip_report_prefix(string $name): string {
    // Quitar prefijo: dígitos seguidos opcionalmente de - o espacio(s).
    $stripped = preg_replace('/^\d+[\-\s]+/', '', $name);
    return strtolower(trim(preg_replace('/\s+/', ' ', $stripped)));
}

function match_report_name(string $own_name, array $rented_names): ?string {
    // 1. Coincidencia exacta.
    if (in_array($own_name, $rented_names, true)) return $own_name;

    // 2. Normalizada (lowercase, espacios).
    $own_norm = strtolower(trim(preg_replace('/\s+/', ' ', $own_name)));
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if ($own_norm === $rn_norm) return $rn;
    }

    // 3. Sin prefijo numérico (001-Informe Global == 01 Informe Global).
    $own_stripped = strip_report_prefix($own_name);
    foreach ($rented_names as $rn) {
        $rn_stripped = strip_report_prefix($rn);
        if ($own_stripped === $rn_stripped) return $rn;
    }

    // 4. Coincidencia parcial (uno contiene al otro).
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if (strpos($own_norm, $rn_norm) !== false || strpos($rn_norm, $own_norm) !== false) {
            return $rn;
        }
    }

    // 5. Parcial sin prefijo.
    foreach ($rented_names as $rn) {
        $rn_stripped = strip_report_prefix($rn);
        if ($own_stripped !== '' && $rn_stripped !== '' &&
            (strpos($own_stripped, $rn_stripped) !== false || strpos($rn_stripped, $own_stripped) !== false)) {
            return $rn;
        }
    }

    return null;
}

/**
 * Actualiza el stat de una columna scormadvancedgrades dentro de components.
 * Modifica el array in-place y devuelve true si hubo cambio.
 */
function update_column_stat(&$components, int $col_index, string $new_stat): bool {
    $comp = is_object($components) ? (array) $components : $components;
    $cols_key = isset($comp['columns']) ? 'columns' : (isset($comp['Columns']) ? 'Columns' : null);
    if (!$cols_key) return false;

    $cols = is_object($comp[$cols_key]) ? (array) $comp[$cols_key] : $comp[$cols_key];
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array) $elements;

    if (!isset($elements[$col_index])) return false;
    $el = is_object($elements[$col_index]) ? (array) $elements[$col_index] : $elements[$col_index];

    $fd = $el['formdata'] ?? $el;
    $fd = is_object($fd) ? (array) $fd : $fd;
    $old_stat = $fd['stat'] ?? $fd['estadistica'] ?? '';

    if ($old_stat === $new_stat) return false;

    // Actualizar el stat.
    if (isset($fd['stat'])) {
        $fd['stat'] = $new_stat;
    } else if (isset($fd['estadistica'])) {
        $fd['estadistica'] = $new_stat;
    } else {
        $fd['stat'] = $new_stat;
    }

    // Reconstruir la estructura.
    if (isset($el['formdata'])) {
        $el['formdata'] = (object) $fd;
    } else {
        $el = $fd;
    }
    $elements[$col_index] = $el;

    $cols['elements'] = array_values($elements);
    $comp[$cols_key] = $cols;
    $components = $comp;
    return true;
}

// ---------------------------------------------------------------------------
// Procesar cursos
// ---------------------------------------------------------------------------
$output = [];
$summary = [
    'total_courses' => 0,
    'courses_with_both' => 0,
    'reports_matched' => 0,
    'columns_matched' => 0,
    'columns_updated' => 0,
    'columns_already_correct' => 0,
    'columns_no_itop_ref' => 0,
    'reports_updated_in_db' => 0,
    'errors' => 0,
    'apply_mode' => $applymode,
];

// Detectar campo de curso del plugin alquilado.
$samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
$columns_info = $samplerow ? array_keys((array) reset($samplerow)) : [];
$rcoursefield = in_array('courseid', $columns_info) ? 'courseid' : (in_array('course', $columns_info) ? 'course' : null);

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) {
        $output[] = ['courseid' => $courseid, 'error' => 'Curso no encontrado'];
        $summary['errors']++;
        continue;
    }

    $summary['total_courses']++;
    $coursedata = [
        'courseid' => $courseid,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
        'reports' => [],
    ];

    // Obtener reportes del propio.
    $ownreports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');

    // Obtener reportes del alquilado.
    $rentedreports = [];
    if ($rcoursefield) {
        $rentedreports = $DB->get_records($rentedtablename, [$rcoursefield => $courseid], 'id ASC');
    }

    if (empty($ownreports) || empty($rentedreports)) {
        $coursedata['note'] = 'Sin reportes en uno o ambos plugins';
        $output[] = $coursedata;
        continue;
    }

    $summary['courses_with_both']++;

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

    // Emparejar reportes.
    foreach ($own_by_name as $own_name => $own_rep) {
        $rented_match = match_report_name($own_name, array_keys($rented_by_name));
        if ($rented_match === null) continue;

        $rented_rep = $rented_by_name[$rented_match];
        $summary['reports_matched']++;

        // Deserializar components.
        $own_components = safe_deserialize($own_rep->components);
        $rented_components = safe_deserialize($rented_rep->components);

        if (!$own_components || !$rented_components) continue;

        // Extraer columnas relevantes.
        $own_sag_cols = extract_columns($own_components, 'scormadvancedgrades');
        // iTOP usa modulestats o modstats o similar.
        $itop_ms_cols = extract_columns($rented_components, 'modulestats');
        if (empty($itop_ms_cols)) {
            $itop_ms_cols = extract_columns($rented_components, 'modstats');
        }
        if (empty($itop_ms_cols)) {
            $itop_ms_cols = extract_columns($rented_components, 'modulestatsv2');
        }
        // También buscar en scormadvancedgrades del alquilado (por si usa el mismo nombre de plugin).
        if (empty($itop_ms_cols)) {
            $itop_ms_cols = extract_columns($rented_components, 'scormadvancedgrades');
        }

        if (empty($own_sag_cols) || empty($itop_ms_cols)) continue;

        $report_data = [
            'own_id' => (int) $own_rep->id,
            'rented_id' => (int) $rented_rep->id,
            'own_name' => $own_name,
            'rented_name' => $rented_match,
            'own_sag_count' => count($own_sag_cols),
            'itop_ms_count' => count($itop_ms_cols),
            'column_updates' => [],
        ];

        // Emparejar columnas: primero por nombre, luego por posición para las restantes.
        $pairs = match_columns_by_header($own_sag_cols, $itop_ms_cols);
        if (empty($pairs)) {
            // Fallback a matching posicional.
            $pairs = match_columns_by_position($own_sag_cols, $itop_ms_cols);
        }

        $report_modified = false;
        foreach ($pairs as $pair) {
            $summary['columns_matched']++;
            $own_col = $pair['own'];
            $itop_col = $pair['itop'];

            // Extraer stat de iTOP.
            $itop_ref = get_itop_stat_reference($itop_col);
            $own_stat = get_column_stat($own_col);
            $own_index = $own_col['_index'] ?? -1;

            $col_update = [
                'own_header' => $pair['own_header'],
                'itop_header' => $pair['itop_header'],
                'match_score' => $pair['match_score'],
                'own_stat_before' => $own_stat,
                'itop_raw_stat' => $itop_ref['raw_stat'],
                'itop_resolved_stat' => $itop_ref['stat'],
                'itop_module_type' => $itop_ref['module_type'],
                'itop_cmid' => $itop_ref['cmid'],
                'itop_metric' => $itop_ref['metric'],
            ];

            if (empty($itop_ref['stat'])) {
                $col_update['action'] = 'skip_no_itop_ref';
                $summary['columns_no_itop_ref']++;
            } else if ($own_stat === $itop_ref['stat']) {
                $col_update['action'] = 'already_correct';
                $summary['columns_already_correct']++;
            } else {
                $col_update['new_stat'] = $itop_ref['stat'];
                $col_update['action'] = $applymode ? 'updated' : 'would_update';

                if ($own_index >= 0 && update_column_stat($own_components, $own_index, $itop_ref['stat'])) {
                    $report_modified = true;
                    $summary['columns_updated']++;
                }
            }

            $report_data['column_updates'][] = $col_update;
        }

        // Guardar en BD si se modificó y estamos en modo apply.
        if ($report_modified && $applymode) {
            try {
                // Serializar de vuelta. Usar la misma función que Moodle.
                // Use raw_serialize to preserve exact PHP types.
                $own_components = normalize_components($own_components);
                $new_components = raw_serialize($own_components);
                $DB->set_field('block_configurable_reports', 'components', $new_components, ['id' => $own_rep->id]);
                $report_data['db_updated'] = true;
                $summary['reports_updated_in_db']++;
            } catch (Throwable $e) {
                $report_data['db_error'] = $e->getMessage();
                $summary['errors']++;
            }
        } else if ($report_modified) {
            $report_data['db_updated'] = false;
            $report_data['note'] = 'dry-run: usar --apply para guardar cambios';
        }

        $coursedata['reports'][] = $report_data;
    }

    $output[] = $coursedata;
}

cr_emit([
    'ok' => true,
    'rented_table' => $rentedtablename,
    'summary' => $summary,
    'courses' => $output,
], 0);
