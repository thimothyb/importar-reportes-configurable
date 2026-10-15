<?php
/**
 * CLI para migrar configuraciones de columnas userstats (iTOP) →
 * userstatsadvanced (nuestro plugin).
 *
 * Para cada curso:
 *   1. Obtiene reportes de AMBOS plugins y los empareja por nombre.
 *   2. Busca columnas userstats (iTOP) y userstatsadvanced (nuestras).
 *   3. Empareja columnas por nombre de cabecera.
 *   4. Compara los items seleccionados (actividades, cuestionarios, etc.).
 *   5. Si iTOP tiene items seleccionados que nuestro plugin no tiene,
 *      los migra mapeando los IDs correspondientes.
 *
 * Los dos plugins usan vocabularios distintos:
 *   - iTOP userstats: stat = "Recursos visitados / Total", con 8 selectores
 *     (cuestionarios, elementos_evaluables, scorms, secciones, sco, tareas,
 *      actividades_recursos, grupo)
 *   - Nuestro userstatsadvanced: stat_type = "recursos_visitados_total",
 *     con selector único "actividades" (cmids)
 *
 * Para items seleccionados, iTOP usa instance_id en sus selectores tipados;
 * nuestro plugin usa cmid en su campo único "actividades".
 * La conversión instance_id → cmid se hace vía course_modules.
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=all|2,3,4           (obligatorio)
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
        $json = json_encode(['ok' => false, 'fatal' => 'json_encode falló'], $flags);
    }
    fwrite(STDOUT, "<<<CR_RESULT>>>" . $json . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con ruta válida'], 2);
}
if (empty($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses'], 2);
}

$applymode = !empty($cliargs['apply']);

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) {
    require_once($ownlocallib);
}
if (!function_exists('urldecode_recursive')) {
    cr_emit(['ok' => false, 'fatal' => 'urldecode_recursive no disponible. ¿Está instalado block_configurable_reports?'], 2);
}

// Detectar tabla iTOP.
$dbman = $DB->get_manager();
$rentedtablename = '';
$candidates = ['block_advanced_reports', 'block_adv_reports', 'block_advreports'];
foreach ($candidates as $tname) {
    if ($dbman->table_exists($tname)) { $rentedtablename = $tname; break; }
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
    } catch (Throwable $e) {}
}
if (empty($rentedtablename)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró tabla iTOP'], 2);
}

// Detectar campo courseid en la tabla iTOP.
$rcoursefield = '';
$dbman2 = $DB->get_manager();
$xmltable = new xmldb_table($rentedtablename);
foreach (['courseid', 'course_id', 'course'] as $cfield) {
    $xmlfield = new xmldb_field($cfield);
    if ($dbman2->field_exists($xmltable, $xmlfield)) {
        $rcoursefield = $cfield;
        break;
    }
}
if (empty($rcoursefield)) {
    // Intentar por introspección directa.
    try {
        $cols = $DB->get_columns($rentedtablename);
        foreach ($cols as $colname => $colinfo) {
            if (stripos($colname, 'course') !== false) {
                $rcoursefield = $colname;
                break;
            }
        }
    } catch (Throwable $e) {}
}
if (empty($rcoursefield)) {
    cr_emit(['ok' => false, 'fatal' => "No se detectó campo de curso en tabla {$rentedtablename}"], 2);
}

// --- Funciones auxiliares ---

function raw_deserialize(string $raw) {
    $raw = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $raw = preg_replace('/THEN\+%22(.+?)%22/', 'THEN+%27${1}%27', $raw);
    $raw = preg_replace('/%60/', '+++', $raw);
    $data = @unserialize($raw);
    if ($data === false && $raw !== serialize(false)) return null;
    return urldecode_recursive($data);
}

function raw_serialize($data): string {
    return serialize(urlencode_recursive($data));
}

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

function safe_deserialize($raw) {
    if (empty($raw)) return null;
    $result = raw_deserialize($raw);
    if ($result !== null) return $result;
    $decoded = @base64_decode($raw, true);
    if ($decoded !== false) {
        $result = raw_deserialize($decoded);
        if ($result !== null) return $result;
    }
    return null;
}

function deep_to_array($val) {
    if (is_object($val)) $val = (array) $val;
    if (is_array($val)) {
        foreach ($val as $k => $v) $val[$k] = deep_to_array($v);
    }
    return $val;
}

function strip_report_prefix(string $name): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/^\d+[\-\s]+/', '', $name))));
}

function match_report_name(string $own_name, array $rented_names): ?string {
    if (in_array($own_name, $rented_names, true)) return $own_name;
    $own_norm = strtolower(trim(preg_replace('/\s+/', ' ', $own_name)));
    foreach ($rented_names as $rn) {
        if (strtolower(trim(preg_replace('/\s+/', ' ', $rn))) === $own_norm) return $rn;
    }
    $own_stripped = strip_report_prefix($own_name);
    foreach ($rented_names as $rn) {
        if (strip_report_prefix($rn) === $own_stripped) return $rn;
    }
    foreach ($rented_names as $rn) {
        $rn_norm = strtolower(trim(preg_replace('/\s+/', ' ', $rn)));
        if (strpos($own_norm, $rn_norm) !== false || strpos($rn_norm, $own_norm) !== false) return $rn;
    }
    return null;
}

function normalize_header(string $h): string {
    $h = mb_strtolower(trim($h));
    $h = preg_replace('/\s+/', ' ', $h);
    return str_replace(
        ['á','é','í','ó','ú','ñ','ü'],
        ['a','e','i','o','u','n','u'],
        $h
    );
}

function extract_plugin_columns($components, string $pluginname): array {
    $columns = [];
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
            $el['_index'] = $idx;
            $columns[] = $el;
        }
    }
    return $columns;
}

function get_col_header($col): string {
    $col = is_object($col) ? (array) $col : $col;
    $fd = $col['formdata'] ?? $col;
    $fd = is_object($fd) ? (array) $fd : $fd;
    return trim($fd['columname'] ?? $fd['column_name'] ?? $fd['columnname']
        ?? $fd['header'] ?? $fd['name'] ?? $fd['titulo'] ?? '');
}

function get_formdata(array $col): array {
    $fd = $col['formdata'] ?? $col;
    return deep_to_array(is_object($fd) ? (array) $fd : $fd);
}

// Campos de selectores de iTOP que contienen IDs de actividades/items.
define('ITOP_SELECTOR_FIELDS', [
    'ids',  // campo principal de iTOP: cmids separados por coma
    'cuestionarios', 'elementos_evaluables', 'scorms', 'secciones',
    'sco', 'tareas', 'actividades_recursos', 'grupo',
    'quizzes', 'quizes', 'quiz',
    'assigns', 'assignments', 'scorm', 'scorm_packages',
    'sections', 'sco_objects', 'scos', 'tasks',
    'activities', 'resources', 'activities_resources',
    'groups', 'grouping',
    'selecteditems', 'selected_items', 'selected',
    'items', 'moduleids', 'module_ids', 'cmids',
    'activityids', 'activity_ids',
]);

// Mapeo selector iTOP → tipo de módulo Moodle (para instance_id → cmid).
define('ITOP_SELECTOR_MODULE_MAP', [
    'cuestionarios' => 'quiz', 'quizzes' => 'quiz', 'quizes' => 'quiz', 'quiz' => 'quiz',
    'tareas' => 'assign', 'assigns' => 'assign', 'assignments' => 'assign', 'tasks' => 'assign',
    'scorms' => 'scorm', 'scorm' => 'scorm', 'scorm_packages' => 'scorm',
]);

// Mapeo stat type → tipo de módulo Moodle (para fallback cross-course).
define('STAT_MODULE_MAP', [
    'quiz_score' => 'quiz',
    'quiz_completed_vs_total' => 'quiz',
    'quiz_completed_vs_total_moodle_criteria' => 'quiz',
    'quiz_average_score' => 'quiz',
    'quiz_best_score' => 'quiz',
    'quiz_num_completed' => 'quiz',
    'assignment_num_completed_vs_total' => 'assign',
    'assignment_average_score' => 'assign',
    'assignment_completed' => 'assign',
    'scorm_score' => 'scorm',
    'scorm_completed_vs_total' => 'scorm',
    'scorm_average_score' => 'scorm',
    'course_modules_completed' => null,  // any module type
]);

function extract_itop_selected_items(array $formdata): array {
    $found = [];
    foreach (ITOP_SELECTOR_FIELDS as $field) {
        if (!isset($formdata[$field])) continue;
        $val = $formdata[$field];
        if (empty($val)) continue;

        $ids = [];
        if (is_array($val)) {
            $ids = array_filter(array_map('intval', $val));
        } else if (is_string($val)) {
            $unserialized = @unserialize($val);
            if (is_array($unserialized)) {
                $ids = array_filter(array_map('intval', $unserialized));
            } else {
                $ids = array_filter(array_map('intval', explode(',', $val)));
            }
        } else if (is_numeric($val)) {
            $ids = [(int)$val];
        }

        if (!empty($ids)) {
            $found[] = [
                'field' => $field,
                'values' => array_values($ids),
                'count' => count($ids),
                'module_type' => ITOP_SELECTOR_MODULE_MAP[$field] ?? null,
            ];
        }
    }
    return $found;
}

function extract_own_selected_items(array $formdata): array {
    $possible = ['actividades', 'activities', 'selected_activities',
                 'cmids', 'selecteditems', 'selected_items', 'items',
                 'activityids', 'activity_ids', 'moduleids', 'module_ids',
                 'recursos', 'resources'];
    $found = [];
    foreach ($possible as $field) {
        if (!isset($formdata[$field])) continue;
        $val = $formdata[$field];
        if (empty($val)) continue;

        $ids = [];
        if (is_array($val)) {
            $ids = array_filter(array_map('intval', $val));
        } else if (is_string($val)) {
            $un = @unserialize($val);
            if (is_array($un)) {
                $ids = array_filter(array_map('intval', $un));
            } else {
                $ids = array_filter(array_map('intval', explode(',', $val)));
            }
        } else if (is_numeric($val)) {
            $ids = [(int)$val];
        }
        if (!empty($ids)) {
            $found[] = ['field' => $field, 'values' => array_values($ids), 'count' => count($ids)];
        }
    }
    return $found;
}

function instance_ids_to_cmids(array $instance_ids, string $module_type, int $courseid): array {
    global $DB;
    $results = [];
    $mod = $DB->get_record('modules', ['name' => $module_type], 'id');
    if (!$mod) {
        foreach ($instance_ids as $iid) {
            $results[] = ['instance_id' => $iid, 'cmid' => 0, 'found' => false, 'error' => "module '$module_type' not found"];
        }
        return $results;
    }
    foreach ($instance_ids as $iid) {
        $cm = $DB->get_record('course_modules', ['module' => $mod->id, 'instance' => $iid, 'course' => $courseid], 'id');
        if ($cm) {
            $results[] = ['instance_id' => $iid, 'cmid' => (int)$cm->id, 'found' => true];
        } else {
            $cm2 = $DB->get_record('course_modules', ['module' => $mod->id, 'instance' => $iid], 'id');
            $results[] = $cm2
                ? ['instance_id' => $iid, 'cmid' => (int)$cm2->id, 'found' => true, 'note' => 'without course filter']
                : ['instance_id' => $iid, 'cmid' => 0, 'found' => false];
        }
    }
    return $results;
}

function convert_itop_selections_to_cmids(array $itop_selections, int $courseid, string $stat_type = ''): array {
    global $DB;
    $all_cmids = [];
    $details = [];

    foreach ($itop_selections as $sel) {
        $field = $sel['field'];
        $values = $sel['values'];
        $module_type = $sel['module_type'];

        if ($module_type) {
            $converted = instance_ids_to_cmids($values, $module_type, $courseid);
            foreach ($converted as $c) {
                if ($c['found'] && $c['cmid'] > 0) $all_cmids[] = $c['cmid'];
            }
            $details[] = ['field' => $field, 'module_type' => $module_type, 'input' => $values, 'conversions' => $converted];
        } else if (in_array($field, ['ids','actividades_recursos','activities','resources','activities_resources',
                                      'activityids','activity_ids','cmids','moduleids','module_ids'])) {
            $valid_in_course = [];
            foreach ($values as $cid) {
                if ($DB->record_exists('course_modules', ['id' => $cid, 'course' => $courseid])) {
                    $valid_in_course[] = $cid;
                }
            }
            if (!empty($valid_in_course)) {
                $all_cmids = array_merge($all_cmids, $valid_in_course);
                $details[] = ['field' => $field, 'note' => 'cmids direct', 'input' => $values, 'valid' => $valid_in_course];
            } else {
                // Cross-course fallback: ids son de otro curso (template).
                // Mapear por posición ordinal: averiguar qué posiciones estaban
                // seleccionadas en el curso origen y seleccionar las mismas en el actual.
                $fallback_module = STAT_MODULE_MAP[$stat_type] ?? null;
                if ($fallback_module !== null) {
                    $mod = $DB->get_record('modules', ['name' => $fallback_module], 'id');
                    if ($mod) {
                        // Determinar el curso origen de los cmids
                        $source_courseid = 0;
                        foreach ($values as $v) {
                            $src_cm = $DB->get_record('course_modules', ['id' => $v], 'course');
                            if ($src_cm) { $source_courseid = (int)$src_cm->course; break; }
                        }

                        // Todos los cmids del mismo módulo en el curso ORIGEN, ordenados
                        $source_all = [];
                        if ($source_courseid > 0) {
                            $src_cms = $DB->get_records('course_modules', [
                                'course' => $source_courseid, 'module' => $mod->id
                            ], 'section ASC, id ASC', 'id');
                            $source_all = array_map('intval', array_keys($src_cms));
                        }

                        // Posiciones ordinales seleccionadas (0-based)
                        $selected_positions = [];
                        foreach ($values as $v) {
                            $pos = array_search((int)$v, $source_all);
                            if ($pos !== false) $selected_positions[] = $pos;
                        }

                        // Todos los cmids del mismo módulo en el curso DESTINO, ordenados
                        $dest_cms = $DB->get_records('course_modules', [
                            'course' => $courseid, 'module' => $mod->id
                        ], 'section ASC, id ASC', 'id');
                        $dest_all = array_map('intval', array_keys($dest_cms));

                        // Mapear posiciones al curso destino
                        $fallback_cmids = [];
                        if (!empty($selected_positions)) {
                            foreach ($selected_positions as $pos) {
                                if (isset($dest_all[$pos])) {
                                    $fallback_cmids[] = $dest_all[$pos];
                                }
                            }
                        }
                        // Si no se pudo mapear posiciones (no existe en origen), tomar todas
                        if (empty($fallback_cmids) && empty($selected_positions)) {
                            $fallback_cmids = $dest_all;
                        }

                        $all_cmids = array_merge($all_cmids, $fallback_cmids);
                        $details[] = ['field' => $field, 'note' => 'cross-course ordinal map',
                                      'input' => $values, 'fallback_module' => $fallback_module,
                                      'source_course' => $source_courseid,
                                      'source_all_count' => count($source_all),
                                      'selected_positions' => $selected_positions,
                                      'dest_all_count' => count($dest_all),
                                      'fallback_cmids' => $fallback_cmids];
                    }
                } else if ($stat_type === 'course_modules_completed' || empty($fallback_module)) {
                    // Generic: no module map available
                    $details[] = ['field' => $field, 'note' => 'cross-course no module map', 'input' => $values, 'stat_type' => $stat_type];
                }
            }
        } else if (in_array($field, ['secciones', 'sections'])) {
            foreach ($values as $sn) {
                $sec = $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sn], 'id, sequence');
                if ($sec && !empty($sec->sequence)) {
                    $all_cmids = array_merge($all_cmids, array_filter(array_map('intval', explode(',', $sec->sequence))));
                }
            }
            $details[] = ['field' => $field, 'note' => 'expanded sections', 'input' => $values];
        } else {
            $details[] = ['field' => $field, 'note' => 'skipped', 'input' => $values];
        }
    }

    $all_cmids = array_values(array_unique($all_cmids));
    sort($all_cmids);
    return ['cmids' => $all_cmids, 'details' => $details];
}

function update_column_selections(&$components, int $col_index, array $new_cmids, string $target_field): bool {
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

    $fd[$target_field] = $new_cmids;
    // El popup select_course_modules.php lee desde selectedcmids (CSV string)
    $fd["selectedcmids"] = implode(",", array_map("intval", $new_cmids));

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

// --- Determinar cursos ---
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
    cr_emit(['ok' => true, 'warning' => 'No hay cursos', 'courses' => [], 'summary' => []], 0);
}

// --- Procesar ---
$output = [];
$summary = [
    'total_courses' => 0,
    'reports_matched' => 0,
    'columns_matched' => 0,
    'columns_with_itop_selections' => 0,
    'columns_migrated' => 0,
    'columns_already_match' => 0,
    'columns_no_selections' => 0,
    'reports_updated_in_db' => 0,
    'errors' => 0,
    'apply_mode' => $applymode,
];

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) { $summary['errors']++; continue; }

    $summary['total_courses']++;
    $coursedata = ['courseid' => $courseid, 'fullname' => $course->fullname, 'reports' => []];

    $ownreports = $DB->get_records('block_configurable_reports', ['courseid' => $courseid], 'id ASC');
    $rentedreports = $rcoursefield
        ? $DB->get_records($rentedtablename, [$rcoursefield => $courseid], 'id ASC')
        : [];

    if (empty($ownreports) || empty($rentedreports)) { $output[] = $coursedata; continue; }

    $own_by_name = [];
    foreach ($ownreports as $r) $own_by_name[$r->name] = $r;
    $rented_by_name = [];
    foreach ($rentedreports as $r) {
        $name = $r->name ?? $r->title ?? "id_{$r->id}";
        $rented_by_name[$name] = $r;
    }

    foreach ($own_by_name as $own_name => $own_rep) {
        $rented_match = match_report_name($own_name, array_keys($rented_by_name));
        if ($rented_match === null) continue;
        $rented_rep = $rented_by_name[$rented_match];

        $own_components = safe_deserialize($own_rep->components);
        $rented_components = safe_deserialize($rented_rep->components);
        if (!$own_components || !$rented_components) continue;

        $own_cols = extract_plugin_columns($own_components, 'userstatsadvanced');
        $itop_cols = extract_plugin_columns($rented_components, 'userstats');
        if (empty($own_cols) && empty($itop_cols)) continue;

        $summary['reports_matched']++;
        $report_data = [
            'own_id' => (int)$own_rep->id,
            'rented_id' => (int)$rented_rep->id,
            'own_name' => $own_name,
            'rented_name' => $rented_match,
            'own_col_count' => count($own_cols),
            'itop_col_count' => count($itop_cols),
            'column_migrations' => [],
        ];

        // Emparejar columnas por cabecera.
        $matched_itop = [];
        foreach ($own_cols as $own_col) {
            $own_header = get_col_header($own_col);
            $own_norm = normalize_header($own_header);
            $best_itop = null;

            foreach ($itop_cols as $iidx => $itop_col) {
                if (in_array($iidx, $matched_itop)) continue;
                $itop_header = get_col_header($itop_col);
                $itop_norm = normalize_header($itop_header);
                if ($own_norm === $itop_norm && $own_norm !== '') {
                    $best_itop = $iidx; break;
                }
                if ($own_norm !== '' && $itop_norm !== '' &&
                    (strpos($own_norm, $itop_norm) !== false || strpos($itop_norm, $own_norm) !== false)) {
                    $best_itop = $iidx;
                }
            }

            if ($best_itop === null) continue;
            $matched_itop[] = $best_itop;
            $itop_col = $itop_cols[$best_itop];
            $summary['columns_matched']++;

            $own_fd = get_formdata($own_col);
            $itop_fd = get_formdata($itop_col);

            $itop_selections = extract_itop_selected_items($itop_fd);
            $own_selections = extract_own_selected_items($own_fd);

            $col_migration = [
                'own_header' => $own_header,
                'itop_header' => get_col_header($itop_col),
                'own_stat_type' => $own_fd['stat_type'] ?? $own_fd['stat'] ?? '',
                'itop_stat' => $itop_fd['stat'] ?? $itop_fd['estadistica'] ?? '',
                'itop_selections_found' => count($itop_selections),
                'own_selections_found' => count($own_selections),
            ];

            if (empty($itop_selections)) {
                $col_migration['action'] = 'no_itop_selections';
                $summary['columns_no_selections']++;
                $report_data['column_migrations'][] = $col_migration;
                continue;
            }

            $summary['columns_with_itop_selections']++;

            $itop_stat = $itop_fd['stat'] ?? '';
            $conversion = convert_itop_selections_to_cmids($itop_selections, $courseid, $itop_stat);
            $col_migration['itop_selections_detail'] = $itop_selections;
            $col_migration['conversion'] = $conversion;
            $col_migration['target_cmids'] = $conversion['cmids'];

            if (empty($conversion['cmids'])) {
                $col_migration['action'] = 'no_valid_cmids';
                $report_data['column_migrations'][] = $col_migration;
                continue;
            }

            // Verificar si ya coinciden.
            $existing_cmids = [];
            foreach ($own_selections as $os) $existing_cmids = array_merge($existing_cmids, $os['values']);
            $existing_cmids = array_values(array_unique($existing_cmids));
            sort($existing_cmids);

            if ($existing_cmids === $conversion['cmids']) {
                // cmids coinciden, pero verificar si falta selectedcmids (CSV para el popup UI)
                $has_selectedcmids = !empty($own_fd['selectedcmids'] ?? '');

                if ($has_selectedcmids) {
                    $col_migration['action'] = 'already_match';
                    $summary['columns_already_match']++;
                    $report_data['column_migrations'][] = $col_migration;
                    continue;
                }
                // Falta selectedcmids — caer al flujo de migración para escribirlo
                $col_migration['action_note'] = 'cmids_match_but_missing_selectedcmids';
            }

            // Determinar campo target.
            $target_field = 'actividades';
            foreach ($own_selections as $os) { $target_field = $os['field']; break; }
            if (empty($own_selections)) {
                foreach (['actividades','activities','selected_activities','cmids','items'] as $pf) {
                    if (array_key_exists($pf, $own_fd)) { $target_field = $pf; break; }
                }
            }

            $col_migration['target_field'] = $target_field;
            $col_migration['existing_cmids'] = $existing_cmids;

            $own_index = $own_col['_index'] ?? -1;
            if ($own_index >= 0) {
                $col_migration['action'] = $applymode ? 'migrated' : 'would_migrate';
                if ($applymode) {
                    $col_migration['applied'] = update_column_selections(
                        $own_components, $own_index, $conversion['cmids'], $target_field
                    );
                    if ($col_migration['applied']) $summary['columns_migrated']++;
                } else {
                    $summary['columns_migrated']++;
                }
            }
            $report_data['column_migrations'][] = $col_migration;
        }

        // Guardar en BD.
        $has_migrations = false;
        foreach ($report_data['column_migrations'] as $cm) {
            if (($cm['action'] ?? '') === 'migrated' && ($cm['applied'] ?? false)) {
                $has_migrations = true; break;
            }
        }
        if ($has_migrations && $applymode) {
            try {
                $own_components = normalize_components($own_components);
                $new_comp = raw_serialize($own_components);
                $DB->set_field('block_configurable_reports', 'components', $new_comp, ['id' => $own_rep->id]);
                $report_data['db_updated'] = true;
                $summary['reports_updated_in_db']++;
            } catch (Throwable $e) {
                $report_data['db_error'] = $e->getMessage();
                $summary['errors']++;
            }
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
