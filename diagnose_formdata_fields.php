<?php
/**
 * Diagnóstico: revisa TODOS los campos de formdata en columnas userstatsadvanced
 * para detectar si usan nombres de campo de iTOP (stat, max_percentage_to_show, etc.)
 * en lugar de los nombres de nuestro plugin (stat_type, maxdisplayvalue, etc.).
 *
 * Esto es clave porque:
 *   - form.php JS toggleFormRows() lee stat_type para decidir si mostrar la UI de selección
 *   - Si formdata tiene "stat" en vez de "stat_type", el JS obtiene vacío y OCULTA toda la UI
 *   - plugin.class.php execute() lee $data->stat_type — sin fallback a $data->stat
 *
 * USO: php diagnose_formdata_fields.php --config=/var/www/html/moodle/adalid/config.php --courses=604
 *       o --courses=all para todos los cursos con userstatsadvanced
 */

$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
    elseif (preg_match('/^--([^=]+)$/', $token, $m)) $cliargs[$m[1]] = true;
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courses=604|all\n");

define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

// Mapeo de campos iTOP → nuestro plugin
$ITOP_TO_OWN = [
    'stat'                       => 'stat_type',
    'max_percentage_to_show'     => 'maxdisplayvalue',
    'display_format'             => 'displayformat',
    'link_to_report_with_modal'  => 'modalreportid',
];

// Valores de stat iTOP → stat_type nuestro
$STAT_VALUE_MAP = [
    'assignment_num_completed_vs_total'     => 'actividades_aprendizaje',
    'quiz_completed_vs_total_moodle_criteria' => 'evaluaciones',
    'course_modules_completed'              => 'contenidos_visualizados',
    'recursos_visitados_total'              => 'recursos_visitados_total',
    'tiempo_total'                          => 'tiempo_total',
    'nota_final'                            => 'nota_final',
    'nota_media'                            => 'nota_media',
    'primera_conexion'                      => 'primera_conexion',
    'ultima_conexion'                       => 'ultima_conexion',
    'registros'                             => 'registros',
    'dias_conexion'                         => 'dias_conexion',
];

$field = $DB->get_manager()->field_exists('block_configurable_reports', 'courseid') ? 'courseid' : 'course';

if (($cliargs['courses'] ?? '') === 'all') {
    $reports = $DB->get_records('block_configurable_reports', [], 'id ASC');
} else {
    $courseids = array_map('intval', explode(',', $cliargs['courses']));
    $reports = [];
    foreach ($courseids as $cid) {
        $reps = $DB->get_records('block_configurable_reports', [$field => $cid], 'id ASC');
        foreach ($reps as $r) $reports[$r->id] = $r;
    }
}

$results = [];
$summary = ['total_userstats_columns' => 0, 'columns_with_itop_fields' => 0, 'columns_ok' => 0, 'field_issues' => []];

foreach ($reports as $rep) {
    $components = cr_unserialize($rep->components);

    // Get elements — handle both array and object
    $elements = null;
    if (is_array($components) && isset($components['columns'])) {
        $cols = $components['columns'];
        if (is_array($cols) && isset($cols['elements'])) {
            $elements = $cols['elements'];
        } elseif (is_object($cols) && isset($cols->elements)) {
            $elements = is_array($cols->elements) ? $cols->elements : (array)$cols->elements;
        }
    } elseif (is_object($components) && isset($components->columns)) {
        $cols = $components->columns;
        if (is_array($cols) && isset($cols['elements'])) {
            $elements = $cols['elements'];
        } elseif (is_object($cols) && isset($cols->elements)) {
            $elements = is_array($cols->elements) ? $cols->elements : (array)$cols->elements;
        }
    }
    if (!$elements) continue;

    foreach ($elements as $idx => $el) {
        $pn = is_object($el) ? ($el->pluginname ?? '') : ($el['pluginname'] ?? '');
        // Check both userstatsadvanced AND userstats (iTOP might not have been renamed)
        if (strpos($pn, 'userstats') === false) continue;

        $summary['total_userstats_columns']++;

        $elid = is_object($el) ? ($el->id ?? '') : ($el['id'] ?? '');
        $fd = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);

        $col_info = [
            'report_id'   => (int)$rep->id,
            'report_name' => $rep->name,
            'col_index'   => $idx,
            'col_id'      => $elid,
            'pluginname'  => $pn,
            'formdata_type' => gettype($fd),
        ];

        // Enumerate ALL formdata fields
        $fd_fields = [];
        if (is_object($fd)) {
            $fd_fields = get_object_vars($fd);
        } elseif (is_array($fd)) {
            $fd_fields = $fd;
        }

        $col_info['formdata_keys'] = array_keys($fd_fields);

        // Check for iTOP field names
        $itop_fields_found = [];
        $own_fields_found = [];
        foreach ($ITOP_TO_OWN as $itop_name => $own_name) {
            if (array_key_exists($itop_name, $fd_fields)) {
                $itop_fields_found[$itop_name] = [
                    'value' => is_scalar($fd_fields[$itop_name]) ? $fd_fields[$itop_name] : gettype($fd_fields[$itop_name]),
                    'should_be' => $own_name,
                ];
            }
            if (array_key_exists($own_name, $fd_fields)) {
                $own_fields_found[$own_name] = is_scalar($fd_fields[$own_name]) ? $fd_fields[$own_name] : gettype($fd_fields[$own_name]);
            }
        }

        // Check stat value mapping
        $current_stat = '';
        if (isset($fd_fields['stat_type'])) {
            $current_stat = (string)$fd_fields['stat_type'];
            $col_info['stat_type'] = $current_stat;
        } elseif (isset($fd_fields['stat'])) {
            $current_stat = (string)$fd_fields['stat'];
            $col_info['stat_iTOP'] = $current_stat;
            $col_info['stat_type_MISSING'] = true;
            if (isset($STAT_VALUE_MAP[$current_stat])) {
                $col_info['stat_should_map_to'] = $STAT_VALUE_MAP[$current_stat];
            }
        }

        // Check selectedcmids
        $col_info['selectedcmids'] = '(not set)';
        if (isset($fd_fields['selectedcmids'])) {
            $val = $fd_fields['selectedcmids'];
            $col_info['selectedcmids'] = is_scalar($val) ? (string)$val : gettype($val);
        }

        // Form JS visibility check: would toggleFormRows show the selection UI?
        $stat_for_js = $fd_fields['stat_type'] ?? '';
        $js_would_show = in_array((string)$stat_for_js, [
            'evaluaciones', 'actividades_aprendizaje', 'contenidos_visualizados', 'recursos_completados'
        ]);
        $col_info['form_js_would_show_selections'] = $js_would_show;

        if (!empty($itop_fields_found)) {
            $col_info['ITOP_FIELDS_DETECTED'] = $itop_fields_found;
            $summary['columns_with_itop_fields']++;
            foreach ($itop_fields_found as $iname => $idata) {
                $summary['field_issues'][] = "report={$rep->id} col={$elid}: {$iname} → should be {$idata['should_be']}";
            }
        } else {
            $col_info['fields_ok'] = true;
            $summary['columns_ok']++;
        }

        $col_info['own_fields'] = $own_fields_found;

        $results[] = $col_info;
    }
}

echo "<<<CR_RESULT>>>" . json_encode([
    'ok' => true,
    'summary' => $summary,
    'columns' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
