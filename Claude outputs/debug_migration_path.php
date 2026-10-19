<?php
/**
 * Diagnóstico: reproduce exactamente la ruta de código de la migración
 * para ver por qué extract_itop_selected_items no encuentra ids.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courseid=604\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);

// === Copiar funciones exactas de migrar_userstats_itop_cr.php ===

function raw_deserialize2(string $raw) {
    $raw = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $raw = preg_replace('/THEN\+%22(.+?)%22/', 'THEN+%27${1}%27', $raw);
    $raw = preg_replace('/%60/', '+++', $raw);
    $data = @unserialize($raw);
    if ($data === false && $raw !== serialize(false)) return null;
    return urldecode_recursive($data);
}

function safe_deserialize2($raw) {
    if (empty($raw)) return null;
    $result = raw_deserialize2($raw);
    if ($result !== null) return $result;
    $decoded = @base64_decode($raw, true);
    if ($decoded !== false) {
        $result = raw_deserialize2($decoded);
        if ($result !== null) return $result;
    }
    return null;
}

// Comparación: decode_comp (como el trace) vs safe_deserialize2 (como la migración)
function decode_comp($raw) {
    if (empty($raw)) return null;
    if (function_exists('cr_unserialize')) return cr_unserialize($raw);
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    return @unserialize($raw2) ?: null;
}

function deep_to_array2($v) {
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) return array_map('deep_to_array2', $v);
    return $v;
}

// --- Obtener reporte iTOP "01 Informe Global" ---
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
$rfield = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$itop_reports = $DB->get_records($rentedtable, [$rfield => $courseid], 'id ASC');

$results = [];

foreach ($itop_reports as $rep) {
    // Método 1: decode_comp (como el trace)
    $comp1 = decode_comp($rep->components);
    // Método 2: safe_deserialize2 (como la migración)
    $comp2 = safe_deserialize2($rep->components);

    if (!$comp1 && !$comp2) continue;

    $report_result = [
        'name' => $rep->name,
        'id' => (int)$rep->id,
        'decode_comp_ok' => $comp1 !== null,
        'safe_deser_ok' => $comp2 !== null,
        'columns' => [],
    ];

    // Procesar con decode_comp
    $data1 = deep_to_array2((array)($comp1 ?? new stdClass));
    $cols1 = (array)($data1['columns'] ?? []);
    $elements1 = (array)($cols1['elements'] ?? []);

    // Procesar con safe_deserialize2
    $data2 = $comp2 !== null ? (is_object($comp2) ? (array)$comp2 : $comp2) : [];
    $cols2_raw = $data2['columns'] ?? null;
    $cols2 = $cols2_raw ? (is_object($cols2_raw) ? (array)$cols2_raw : $cols2_raw) : [];
    $elements2 = $cols2['elements'] ?? [];
    if (is_object($elements2)) $elements2 = (array)$elements2;

    $max_idx = max(count($elements1), count($elements2));
    for ($i = 0; $i < $max_idx; $i++) {
        $el1 = isset($elements1[$i]) ? deep_to_array2($elements1[$i]) : null;
        $el2 = isset($elements2[$i]) ? (is_object($elements2[$i]) ? (array)$elements2[$i] : $elements2[$i]) : null;

        $col_info = ['idx' => $i];

        if ($el1) {
            $fd1 = $el1['formdata'] ?? $el1;
            $col_info['trace_plugin'] = $el1['pluginname'] ?? '?';
            $col_info['trace_header'] = $fd1['columname'] ?? '';
            $col_info['trace_has_ids'] = isset($fd1['ids']) && !empty($fd1['ids']);
            if ($col_info['trace_has_ids']) {
                $col_info['trace_ids_type'] = gettype($fd1['ids']);
                $col_info['trace_ids_value'] = is_string($fd1['ids']) ? substr($fd1['ids'], 0, 80) : json_encode($fd1['ids']);
            }
        }

        if ($el2) {
            $el2arr = is_object($el2) ? (array)$el2 : $el2;
            $fd2 = $el2arr['formdata'] ?? $el2arr;
            $fd2 = is_object($fd2) ? (array)$fd2 : $fd2;
            $col_info['migr_plugin'] = $el2arr['pluginname'] ?? '?';
            $col_info['migr_header'] = $fd2['columname'] ?? '';
            $col_info['migr_has_ids'] = isset($fd2['ids']) && !empty($fd2['ids']);
            if ($col_info['migr_has_ids']) {
                $col_info['migr_ids_type'] = gettype($fd2['ids']);
                $col_info['migr_ids_value'] = is_string($fd2['ids']) ? substr($fd2['ids'], 0, 80) : json_encode($fd2['ids']);
            }
            $col_info['migr_fd_keys'] = array_keys($fd2);
            // Check all ITOP_SELECTOR_FIELDS
            $selector_fields = ['ids','cuestionarios','elementos_evaluables','scorms','secciones',
                'sco','tareas','actividades_recursos','grupo','quizzes','quizes','quiz',
                'assigns','assignments','scorm','scorm_packages','sections','sco_objects','scos','tasks',
                'activities','resources','activities_resources','groups','grouping',
                'selecteditems','selected_items','selected','items','moduleids','module_ids','cmids',
                'activityids','activity_ids'];
            $found_selectors = [];
            foreach ($selector_fields as $sf) {
                if (isset($fd2[$sf]) && !empty($fd2[$sf])) {
                    $found_selectors[$sf] = is_string($fd2[$sf]) ? substr($fd2[$sf],0,60) : gettype($fd2[$sf]);
                }
            }
            $col_info['migr_found_selectors'] = $found_selectors;
        }

        // Only include userstats columns with or without ids
        $plugin1 = $col_info['trace_plugin'] ?? '';
        $plugin2 = $col_info['migr_plugin'] ?? '';
        if (strpos($plugin1, 'userstats') !== false || strpos($plugin2, 'userstats') !== false) {
            $report_result['columns'][] = $col_info;
        }
    }

    if (!empty($report_result['columns'])) {
        $results[] = $report_result;
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
