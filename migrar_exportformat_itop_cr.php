<?php
/**
 * CLI para migrar formato de exportación de reportes iTOP → Configurable Reports.
 *
 * Los reportes tienen un campo 'export' que define los formatos de descarga
 * disponibles (csv, xls, pdf, slk, etc.). Este script:
 *   1. Empareja reportes por nombre entre ambos plugins.
 *   2. Compara el campo 'export' de cada par.
 *   3. Copia el valor de exportación de iTOP al reporte nuestro cuando difieren.
 *
 * Nota: el formato 'slk' no está soportado en nuestro plugin; si iTOP tiene
 * 'slk' lo reemplaza por 'pdf' al migrar.
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
$xmltable = new xmldb_table($rentedtablename);
foreach (['courseid', 'course_id', 'course'] as $cfield) {
    $xmlfield = new xmldb_field($cfield);
    if ($dbman->field_exists($xmltable, $xmlfield)) {
        $rcoursefield = $cfield;
        break;
    }
}
if (empty($rcoursefield)) {
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

// Detectar si la tabla iTOP tiene campo 'export'.
$has_rented_export = false;
$xmlfield_export = new xmldb_field('export');
if ($dbman->field_exists($xmltable, $xmlfield_export)) {
    $has_rented_export = true;
}
// También verificar campo 'exports' como alternativa.
$rented_export_field = '';
if ($has_rented_export) {
    $rented_export_field = 'export';
} else {
    $xmlfield_exports = new xmldb_field('exports');
    if ($dbman->field_exists($xmltable, $xmlfield_exports)) {
        $rented_export_field = 'exports';
    }
}

// Verificar campo export en nuestra tabla.
$own_table = new xmldb_table('block_configurable_reports');
$own_export_field = '';
$xmlfield_own_exp = new xmldb_field('export');
if ($dbman->field_exists($own_table, $xmlfield_own_exp)) {
    $own_export_field = 'export';
} else {
    $xmlfield_own_exps = new xmldb_field('exports');
    if ($dbman->field_exists($own_table, $xmlfield_own_exps)) {
        $own_export_field = 'exports';
    }
}

if (empty($rented_export_field) && empty($own_export_field)) {
    cr_emit(['ok' => false, 'fatal' => 'Ninguna tabla tiene campo de exportación'], 2);
}

// --- Funciones auxiliares ---

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

/**
 * Normaliza un string de exportación para comparar.
 * Convierte a array ordenado, quita vacíos.
 */
function parse_export_formats(string $export_str): array {
    $formats = array_filter(array_map('trim', explode(',', $export_str)));
    $formats = array_values(array_unique($formats));
    sort($formats);
    return $formats;
}

/**
 * Convierte formatos de iTOP a los soportados por nuestro plugin.
 * slk → pdf (slk no está soportado en nuestro plugin).
 */
function convert_export_formats(array $itop_formats): array {
    $map = [
        'slk' => 'pdf',  // slk no soportado, reemplazar por pdf.
    ];

    $converted = [];
    foreach ($itop_formats as $fmt) {
        $fmt_lower = strtolower(trim($fmt));
        if (isset($map[$fmt_lower])) {
            $converted[] = $map[$fmt_lower];
        } else {
            $converted[] = $fmt_lower;
        }
    }
    $converted = array_values(array_unique($converted));
    sort($converted);
    return $converted;
}

/**
 * Reconstruye el string de exportación en el formato que usa Moodle.
 * Cada formato separado por coma, con coma final.
 */
function build_export_string(array $formats): string {
    if (empty($formats)) return '';
    return implode(',', $formats) . ',';
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
    'exports_differ' => 0,
    'exports_updated' => 0,
    'exports_already_match' => 0,
    'exports_no_field' => 0,
    'errors' => 0,
    'apply_mode' => $applymode,
    'rented_export_field' => $rented_export_field,
    'own_export_field' => $own_export_field,
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
        $summary['reports_matched']++;

        // Obtener valores de export.
        $own_export_raw = '';
        if ($own_export_field && isset($own_rep->{$own_export_field})) {
            $own_export_raw = (string)$own_rep->{$own_export_field};
        }
        $rented_export_raw = '';
        if ($rented_export_field && isset($rented_rep->{$rented_export_field})) {
            $rented_export_raw = (string)$rented_rep->{$rented_export_field};
        }

        $own_formats = parse_export_formats($own_export_raw);
        $itop_formats = parse_export_formats($rented_export_raw);
        $converted_formats = convert_export_formats($itop_formats);

        $report_data = [
            'own_id' => (int)$own_rep->id,
            'rented_id' => (int)$rented_rep->id,
            'own_name' => $own_name,
            'rented_name' => $rented_match,
            'own_export_raw' => $own_export_raw,
            'rented_export_raw' => $rented_export_raw,
            'own_formats' => $own_formats,
            'itop_formats' => $itop_formats,
            'converted_formats' => $converted_formats,
        ];

        // Si no hay campo de export en alguna tabla, reportar.
        if (empty($rented_export_field) || empty($rented_export_raw)) {
            $report_data['action'] = 'no_itop_export';
            $summary['exports_no_field']++;
            $coursedata['reports'][] = $report_data;
            continue;
        }
        if (empty($own_export_field)) {
            $report_data['action'] = 'no_own_export_field';
            $summary['exports_no_field']++;
            $coursedata['reports'][] = $report_data;
            continue;
        }

        // Comparar.
        if ($own_formats === $converted_formats) {
            $report_data['action'] = 'already_match';
            $summary['exports_already_match']++;
            $coursedata['reports'][] = $report_data;
            continue;
        }

        // Hay diferencia.
        $summary['exports_differ']++;
        $new_export_str = build_export_string($converted_formats);
        $report_data['new_export'] = $new_export_str;
        $report_data['new_formats'] = $converted_formats;

        if ($applymode) {
            try {
                $DB->set_field('block_configurable_reports', $own_export_field, $new_export_str, ['id' => $own_rep->id]);
                $report_data['action'] = 'updated';
                $summary['exports_updated']++;
            } catch (Throwable $e) {
                $report_data['action'] = 'error';
                $report_data['error'] = $e->getMessage();
                $summary['errors']++;
            }
        } else {
            $report_data['action'] = 'would_update';
            $summary['exports_updated']++;
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
