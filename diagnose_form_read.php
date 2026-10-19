<?php
/**
 * Diagnóstico: simula exactamente lo que hace get_current_selected_cmids_from_customdata()
 * en form.php para verificar por qué la UI no muestra las selecciones.
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
$field = $DB->get_manager()->field_exists('block_configurable_reports', 'courseid') ? 'courseid' : 'course';
$reports = $DB->get_records('block_configurable_reports', [$field => $courseid], 'id ASC');

$results = [];
foreach ($reports as $rep) {
    $components = cr_unserialize($rep->components);

    $info = [
        'report_id' => (int)$rep->id,
        'report_name' => $rep->name,
        'components_type' => gettype($components),
    ];

    // Check columns access with array syntax (what form.php does)
    $columns_via_array = $components['columns'] ?? null;
    $info['columns_via_array_type'] = gettype($columns_via_array);

    // Check columns access with object syntax
    if (is_object($components)) {
        $columns_via_obj = $components->columns ?? null;
        $info['columns_via_obj_type'] = gettype($columns_via_obj);
    }

    // If columns is accessible, check elements
    $cols = $columns_via_array;
    if ($cols === null && is_object($components)) {
        $cols = $components->columns ?? null;
    }

    if ($cols !== null) {
        $info['columns_actual_type'] = gettype($cols);

        // Try array access for elements (what form.php does)
        if (is_array($cols)) {
            $elements = $cols['elements'] ?? null;
            $info['elements_via_array'] = gettype($elements);
        } else {
            // cols is object, array access fails
            $info['elements_via_array'] = 'N/A (cols is ' . gettype($cols) . ')';
            // Try object access
            if (is_object($cols)) {
                $elements = $cols->elements ?? null;
                $info['elements_via_obj'] = gettype($elements);
            }
        }
    }

    // Now find userstats columns and simulate form read
    $componentname = 'columns';
    $elements_arr = null;

    // Exactly mimic form.php line 63
    $form_elements = $components[$componentname]['elements'] ?? [];
    $info['form_elements_count'] = is_array($form_elements) ? count($form_elements) : 'NOT_ARRAY:' . gettype($form_elements);

    // Get elements the right way for checking
    if (is_array($components) && isset($components['columns'])) {
        $colsec = $components['columns'];
        if (is_array($colsec) && isset($colsec['elements'])) {
            $elements_arr = $colsec['elements'];
        } elseif (is_object($colsec) && isset($colsec->elements)) {
            $elements_arr = is_array($colsec->elements) ? $colsec->elements : (array)$colsec->elements;
        }
    }

    if ($elements_arr) {
        foreach ($elements_arr as $idx => $el) {
            $pn = is_object($el) ? ($el->pluginname ?? '') : ($el['pluginname'] ?? '');
            if (strpos($pn, 'userstats') === false) continue;

            $elid = is_object($el) ? ($el->id ?? '') : ($el['id'] ?? '');
            $fd = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);

            $el_info = [
                'idx' => $idx,
                'el_id' => $elid,
                'el_type' => gettype($el),
                'fd_type' => gettype($fd),
            ];

            // Check selectedcmids access both ways
            if (is_object($fd)) {
                $el_info['fd_selectedcmids_obj'] = isset($fd->selectedcmids) ? (string)$fd->selectedcmids : 'NOT_SET';
            }
            if (is_array($fd)) {
                $el_info['fd_selectedcmids_arr'] = isset($fd['selectedcmids']) ? (string)$fd['selectedcmids'] : 'NOT_SET';
            }

            // Simulate form.php lines 78-83 exactly
            $form_result = '';
            if (is_object($fd) && !empty($fd->selectedcmids)) {
                $form_result = (string)$fd->selectedcmids;
            }
            if (is_array($fd) && !empty($fd['selectedcmids'])) {
                $form_result = (string)$fd['selectedcmids'];
            }
            $el_info['form_would_return'] = $form_result ?: '(empty)';

            $info['userstats_columns'][] = $el_info;
        }
    }

    // Only include reports with userstats columns
    if (!empty($info['userstats_columns'])) {
        $results[] = $info;
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
