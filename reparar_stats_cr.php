<?php
/**
 * Reparar reportes cuyos components quedaron corruptos después de la
 * migración de stats (conversión array/stdClass).
 *
 * Este script:
 *   1. Lee el campo components con unserialize crudo (sin cr_unserialize).
 *   2. Navega la estructura preservando tipos (stdClass vs array).
 *   3. Aplica los cambios de stat usando la misma lógica del script de migración.
 *   4. Serializa de vuelta con serialize + urlencode_recursive (cr_serialize).
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --reportids=10011,10014       (obligatorio: IDs de reportes a reparar)
 *   --apply                       (opcional: sin este flag solo diagnostica)
 *
 * @package   block_configurable_reports
 * @copyright 2026 Awakelab
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
        $json = json_encode(['ok' => false, 'fatal' => 'json_encode falló'], $flags);
    }
    fwrite(STDOUT, "<<<CR_RESULT>>>" . $json . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con ruta válida'], 2);
}
if (empty($cliargs['reportids'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --reportids (IDs separados por coma)'], 2);
}

$applymode = !empty($cliargs['apply']);
$reportids = array_filter(array_map('intval', explode(',', $cliargs['reportids'])));

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');

// Detectar tabla iTOP.
$dbman = $DB->get_manager();
$rentedtablename = '';
$candidates = ['block_advanced_reports', 'block_adv_reports', 'block_advreports'];
foreach ($candidates as $tname) {
    if ($dbman->table_exists($tname)) { $rentedtablename = $tname; break; }
}
if (empty($rentedtablename)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró tabla iTOP'], 2);
}

// Detectar campo courseid en tabla iTOP.
$rcoursefield = '';
$xmltable = new xmldb_table($rentedtablename);
foreach (['courseid', 'course_id', 'course'] as $cf) {
    $xmlf = new xmldb_field($cf);
    if ($dbman->field_exists($xmltable, $xmlf)) { $rcoursefield = $cf; break; }
}
if (empty($rcoursefield)) {
    $cols = $DB->get_columns($rentedtablename);
    foreach ($cols as $cn => $ci) {
        if (stripos($cn, 'course') !== false) { $rcoursefield = $cn; break; }
    }
}

/**
 * Decode components preserving exact PHP types (stdClass stays stdClass).
 */
function raw_deserialize(string $raw) {
    // Same pre-processing as cr_unserialize, but WITHOUT (array) cast.
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
 * Encode components back preserving types.
 */
function raw_serialize($data): string {
    return serialize(urlencode_recursive($data));
}

/**
 * Extract the stat value from a column's formdata, preserving type navigation.
 */
function get_stat_from_column($col): string {
    $fd = null;
    if (is_object($col)) {
        $fd = $col->formdata ?? null;
    } else if (is_array($col)) {
        $fd = $col['formdata'] ?? null;
    }
    if ($fd === null) return '';

    if (is_object($fd)) {
        return (string)($fd->stat ?? $fd->stat_type ?? '');
    } else if (is_array($fd)) {
        return (string)($fd['stat'] ?? $fd['stat_type'] ?? '');
    }
    return '';
}

/**
 * Set stat in a column's formdata, preserving types.
 */
function set_stat_in_column(&$col, string $new_stat): bool {
    $fd = null;
    if (is_object($col)) {
        $fd = &$col->formdata;
    } else if (is_array($col)) {
        $fd = &$col['formdata'];
    }
    if ($fd === null) return false;

    if (is_object($fd)) {
        if (isset($fd->stat_type)) {
            $fd->stat_type = $new_stat;
        } else {
            $fd->stat = $new_stat;
        }
    } else if (is_array($fd)) {
        if (isset($fd['stat_type'])) {
            $fd['stat_type'] = $new_stat;
        } else {
            $fd['stat'] = $new_stat;
        }
    }
    return true;
}

/**
 * Get column header from formdata.
 */
function get_header_from_column($col): string {
    $fd = null;
    if (is_object($col)) {
        $fd = $col->formdata ?? null;
    } else if (is_array($col)) {
        $fd = $col['formdata'] ?? null;
    }
    if ($fd === null) return '?';
    if (is_object($fd)) return (string)($fd->columname ?? $fd->column ?? '');
    if (is_array($fd)) return (string)($fd['columname'] ?? $fd['column'] ?? '');
    return '?';
}

/**
 * Get plugin name from column element.
 */
function get_pluginname($col): string {
    if (is_object($col)) return (string)($col->pluginname ?? '');
    if (is_array($col)) return (string)($col['pluginname'] ?? '');
    return '';
}

/**
 * Parse iTOP stat reference: quiz_score_id3577 -> quiz:cmid:score
 */
function parse_itop_stat(string $raw_stat, int $courseid): array {
    global $DB;
    $result = ['raw' => $raw_stat, 'stat' => '', 'module_type' => '', 'cmid' => 0, 'metric' => ''];

    // Pattern: {module}_{metric}_id{instance}
    if (!preg_match('/^(\w+?)_(score|date|grade|completion|status|lastaccess|attempts|timeopen|timeclose)_id(\d+)$/i', $raw_stat, $m)) {
        return $result;
    }

    $module_type = strtolower($m[1]);
    $metric = strtolower($m[2]);
    $instance_id = (int)$m[3];

    // Convert instance ID to cmid.
    $cmid = $DB->get_field_sql(
        "SELECT cm.id FROM {course_modules} cm
         JOIN {modules} m ON m.id = cm.module
         WHERE m.name = :modname AND cm.instance = :instance AND cm.course = :courseid",
        ['modname' => $module_type, 'instance' => $instance_id, 'courseid' => $courseid]
    );

    if (!$cmid) return $result;

    $result['module_type'] = $module_type;
    $result['cmid'] = (int)$cmid;
    $result['metric'] = $metric;
    $result['stat'] = "{$module_type}:{$cmid}:{$metric}";
    return $result;
}

// ========== MAIN ==========
$output = [];
$summary = ['total' => 0, 'repaired' => 0, 'errors' => 0, 'columns_fixed' => 0, 'format_fixed' => 0];

/**
 * Recursively convert arrays back to stdClass where the original format used objects.
 * The components structure uses stdClass for sections (columns, conditions, etc.)
 * and arrays only for 'elements' lists.
 */
function array_to_object_recursive($data, int $depth = 0) {
    if ($depth > 20) return $data; // safety
    if (is_array($data)) {
        // Check if this is an associative array (should be stdClass) or a list (should stay array).
        // Lists have sequential integer keys starting from 0.
        $is_list = array_keys($data) === range(0, count($data) - 1);
        if ($is_list && count($data) > 0) {
            // This is a list (like elements array) — keep as array, recurse into items.
            $result = [];
            foreach ($data as $item) {
                $result[] = array_to_object_recursive($item, $depth + 1);
            }
            return $result;
        } else if (!empty($data)) {
            // Associative array — convert to stdClass.
            $obj = new \stdClass();
            foreach ($data as $k => $v) {
                $obj->$k = array_to_object_recursive($v, $depth + 1);
            }
            return $obj;
        }
        return $data;
    }
    if (is_object($data)) {
        // Already stdClass — recurse into properties.
        foreach (get_object_vars($data) as $k => $v) {
            $data->$k = array_to_object_recursive($v, $depth + 1);
        }
        return $data;
    }
    return $data;
}

foreach ($reportids as $rid) {
    $own_rep = $DB->get_record('block_configurable_reports', ['id' => $rid]);
    if (!$own_rep) {
        $output[] = ['id' => $rid, 'error' => 'not found'];
        $summary['errors']++;
        continue;
    }
    $summary['total']++;

    $courseid = (int)$own_rep->courseid;

    // 1. Read with raw deserialize (preserves types as stored).
    $components = raw_deserialize($own_rep->components);
    if (!$components) {
        $output[] = ['id' => $rid, 'error' => 'cannot deserialize'];
        $summary['errors']++;
        continue;
    }

    // 2. Detect format corruption: top level should be stdClass, not array.
    $needs_format_fix = is_array($components);
    $report_info = [
        'id' => $rid,
        'name' => $own_rep->name,
        'courseid' => $courseid,
        'top_level_type' => is_object($components) ? 'stdClass' : 'array',
        'needs_format_fix' => $needs_format_fix,
        'changes' => [],
    ];

    // Navigate components regardless of type.
    $is_obj = is_object($components);
    $columns_section = $is_obj ? ($components->columns ?? null) : ($components['columns'] ?? null);
    $elements = [];
    if ($columns_section) {
        $elements = is_object($columns_section) ? ($columns_section->elements ?? []) : ($columns_section['elements'] ?? []);
    }

    // 3. Try to fix stats if iTOP report available.
    $stat_modified = false;
    $rented_rep = null;
    if ($rcoursefield) {
        $rented_reports = $DB->get_records($rentedtablename, [$rcoursefield => $courseid], 'id ASC');
        $own_name_norm = strtolower(trim(preg_replace('/^\d+[\-\s]+/', '', $own_rep->name)));
        foreach ($rented_reports as $rr) {
            $rr_name = $rr->name ?? $rr->title ?? '';
            $rr_name_norm = strtolower(trim(preg_replace('/^\d+[\-\s]+/', '', $rr_name)));
            if ($rr_name_norm === $own_name_norm) {
                $rented_rep = $rr;
                break;
            }
        }
    }

    if ($rented_rep && !empty($elements)) {
        // Deserialize iTOP report.
        $rented_raw = $rented_rep->components ?? '';
        $rented_components = raw_deserialize($rented_raw);
        if (!$rented_components) {
            $decoded = @base64_decode($rented_raw);
            if ($decoded) $rented_components = raw_deserialize($decoded);
        }

        if ($rented_components) {
            $rc_cols_section = is_object($rented_components)
                ? ($rented_components->columns ?? null)
                : ($rented_components['columns'] ?? null);
            $rc_elements = [];
            if ($rc_cols_section) {
                $rc_elements = is_object($rc_cols_section)
                    ? ($rc_cols_section->elements ?? [])
                    : ($rc_cols_section['elements'] ?? []);
            }

            $itop_by_header = [];
            foreach ($rc_elements as $rc_el) {
                $pname = get_pluginname($rc_el);
                if (!in_array($pname, ['modulestats', 'modstats', 'scormadvancedgrades', 'modulestatsv2'])) continue;
                $header = get_header_from_column($rc_el);
                $stat = get_stat_from_column($rc_el);
                $itop_by_header[strtolower(trim($header))] = ['stat' => $stat, 'header' => $header];
            }

            foreach ($elements as $idx => &$el) {
                $pname = get_pluginname($el);
                if ($pname !== 'scormadvancedgrades') continue;

                $header = get_header_from_column($el);
                $current_stat = get_stat_from_column($el);
                $header_key = strtolower(trim($header));

                if (!isset($itop_by_header[$header_key])) continue;

                $itop_stat_raw = $itop_by_header[$header_key]['stat'];
                $parsed = parse_itop_stat($itop_stat_raw, $courseid);

                if (empty($parsed['stat'])) continue;
                if ($current_stat === $parsed['stat']) {
                    $report_info['changes'][] = [
                        'header' => $header, 'stat' => $current_stat, 'action' => 'already_correct',
                    ];
                    continue;
                }

                $change = [
                    'header' => $header,
                    'stat_before' => $current_stat,
                    'stat_after' => $parsed['stat'],
                    'itop_raw' => $itop_stat_raw,
                ];
                if ($applymode) {
                    set_stat_in_column($el, $parsed['stat']);
                    $stat_modified = true;
                    $change['action'] = 'fixed';
                    $summary['columns_fixed']++;
                } else {
                    $change['action'] = 'would_fix';
                    $summary['columns_fixed']++;
                }
                $report_info['changes'][] = $change;
            }
            unset($el);
        }
    }

    // 4. Re-serialize if format is corrupted OR stats were modified.
    $needs_save = $needs_format_fix || $stat_modified;
    $report_info['needs_save'] = $needs_save;

    if ($needs_save) {
        if ($applymode) {
            // Re-pack elements if stats changed.
            if ($stat_modified && $columns_section) {
                if (is_object($columns_section)) {
                    $columns_section->elements = $elements;
                } else {
                    $columns_section['elements'] = $elements;
                }
                if ($is_obj) {
                    $components->columns = $columns_section;
                } else {
                    $components['columns'] = $columns_section;
                }
            }

            // Convert corrupted arrays back to stdClass recursively.
            if ($needs_format_fix) {
                $components = array_to_object_recursive($components);
                $summary['format_fixed']++;
                $report_info['format_restored'] = true;
            }

            try {
                $new_raw = raw_serialize($components);
                $DB->set_field('block_configurable_reports', 'components', $new_raw, ['id' => $rid]);
                $report_info['db_updated'] = true;
                $summary['repaired']++;
            } catch (Throwable $e) {
                $report_info['db_error'] = $e->getMessage();
                $summary['errors']++;
            }
        } else {
            $report_info['action'] = 'would_repair';
            if ($needs_format_fix) {
                $summary['format_fixed']++;
            }
            $summary['repaired']++;
        }
    } else {
        $report_info['action'] = 'already_ok';
    }

    $output[] = $report_info;
}

cr_emit(['ok' => true, 'summary' => $summary, 'reports' => $output], 0);
