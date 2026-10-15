<?php
/**
 * Diagnóstico rápido: verifica el TIPO de cada elemento en components['columns']['elements']
 * después de cr_unserialize, y simula exactamente lo que form.php línea 70 haría.
 *
 * Si los elementos son stdClass, form.php los SALTA y nunca lee selectedcmids.
 *
 * USO: php diagnose_element_types.php --config=/var/www/html/moodle/adalid/config.php --courses=604
 */

$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
    elseif (preg_match('/^--([^=]+)$/', $token, $m)) $cliargs[$m[1]] = true;
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courses=604\n");

define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$field = $DB->get_manager()->field_exists('block_configurable_reports', 'courseid') ? 'courseid' : 'course';
$courseids = array_map('intval', explode(',', $cliargs['courses'] ?? '604'));
$reports = [];
foreach ($courseids as $cid) {
    $reps = $DB->get_records('block_configurable_reports', [$field => $cid], 'id ASC');
    foreach ($reps as $r) $reports[$r->id] = $r;
}

$results = [];

foreach ($reports as $rep) {
    $components = cr_unserialize($rep->components);

    $report_info = [
        'report_id' => (int)$rep->id,
        'report_name' => $rep->name,
        'components_type' => gettype($components),
    ];

    // Check columns access exactly like form.php line 63
    $componentname = 'columns';

    // form.php line 63: $elements = $components[$componentname]['elements'] ?? [];
    // This uses ARRAY syntax. If $components['columns'] is an object, ['elements'] fails.
    $columns_section = null;
    $columns_access_method = '';

    if (is_array($components) && array_key_exists($componentname, $components)) {
        $columns_section = $components[$componentname];
        $columns_access_method = 'array[$componentname]';
    } elseif (is_object($components) && isset($components->$componentname)) {
        $columns_section = $components->$componentname;
        $columns_access_method = 'object->$componentname';
    }

    $report_info['columns_access'] = $columns_access_method;
    $report_info['columns_type'] = gettype($columns_section);

    // Now get elements
    $elements = null;
    $elements_access_method = '';

    if (is_array($columns_section) && array_key_exists('elements', $columns_section)) {
        $elements = $columns_section['elements'];
        $elements_access_method = 'array[elements]';
    } elseif (is_object($columns_section) && isset($columns_section->elements)) {
        $elements = $columns_section->elements;
        $elements_access_method = 'object->elements';
    }

    $report_info['elements_access'] = $elements_access_method;
    $report_info['elements_type'] = gettype($elements);
    $report_info['elements_count'] = is_countable($elements) ? count($elements) : 'N/A';

    // What form.php line 63 would actually get:
    $form_line63_result = $components[$componentname]['elements'] ?? [];
    $report_info['form_line63_type'] = gettype($form_line63_result);
    $report_info['form_line63_count'] = is_countable($form_line63_result) ? count($form_line63_result) : 'N/A';

    // Check each element type (first 3 userstats only)
    $checked = 0;
    if ($elements && (is_array($elements) || $elements instanceof \Traversable)) {
        $el_arr = is_array($elements) ? $elements : (array)$elements;
        foreach ($el_arr as $idx => $el) {
            $pn = is_object($el) ? ($el->pluginname ?? '') : ($el['pluginname'] ?? '');
            if (strpos($pn, 'userstats') === false) continue;
            if ($checked >= 5) break;
            $checked++;

            $el_type = gettype($el);
            $elid = is_object($el) ? ($el->id ?? '') : ($el['id'] ?? '');

            // Simulate form.php line 70 EXACTLY
            $form_would_skip = !is_array($el) || !array_key_exists('id', $el);

            // Get selectedcmids
            $fd = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);
            $cmids = '';
            if (is_object($fd) && !empty($fd->selectedcmids)) {
                $cmids = (string)$fd->selectedcmids;
            } elseif (is_array($fd) && !empty($fd['selectedcmids'])) {
                $cmids = (string)$fd['selectedcmids'];
            }

            $stat = '';
            if (is_object($fd) && !empty($fd->stat_type)) {
                $stat = (string)$fd->stat_type;
            } elseif (is_array($fd) && !empty($fd['stat_type'])) {
                $stat = (string)$fd['stat_type'];
            }

            $report_info['sample_elements'][] = [
                'idx' => $idx,
                'id' => $elid,
                'element_type' => $el_type,
                'FORM_LINE70_WOULD_SKIP' => $form_would_skip,
                'stat_type' => $stat,
                'selectedcmids' => $cmids ?: '(empty)',
                'has_data_but_form_skips' => ($form_would_skip && !empty($cmids)),
            ];
        }
    }

    $results[] = $report_info;
}

echo "<<<CR_RESULT>>>" . json_encode([
    'ok' => true,
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
