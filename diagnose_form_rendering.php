<?php
/**
 * Comprehensive diagnostic: simulates the EXACT editplugin.php + form.php flow.
 *
 * For each userstatsadvanced column in a course, this script:
 * 1. Simulates what editplugin.php does when loading the edit page (cr_unserialize, find element by cid)
 * 2. Simulates what form.php's get_current_selected_cmids_from_customdata() returns
 * 3. Simulates what set_data($formdata) would provide
 * 4. Simulates what the JS refreshLabel() would display
 * 5. Checks if stat_type allows showing the selection fields (toggleFormRows)
 *
 * This gives us the EXACT view the user would see when editing each column.
 *
 * USO: php diagnose_form_rendering.php --config=/path/to/config.php --courses=604
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
    // ===== STEP 1: Simulate editplugin.php lines 86-99 =====
    $components = cr_unserialize($rep->components);
    $comp = 'columns';
    $elements_ep = isset($components[$comp]['elements']) ? $components[$comp]['elements'] : [];

    if (!$elements_ep) continue;

    foreach ($elements_ep as $e_idx => $e) {
        // editplugin.php line 92-93: strict array check
        if (!is_array($e) || !array_key_exists('id', $e)) {
            continue;
        }

        $pn = $e['pluginname'] ?? '';
        if (strpos($pn, 'userstats') === false) continue;

        $cid_val = $e['id'];
        $cdata = $e;

        // ===== STEP 2: Simulate form.php get_current_selected_cmids_from_customdata() =====
        // Re-do cr_unserialize fresh (as form.php would from $report->components)
        $components2 = cr_unserialize($rep->components);
        $componentname = 'columns';
        $componentid = (string)$cid_val;

        $form_result = '';

        // Lines 66-71: get column section
        $columnsection = null;
        if (is_array($components2) && isset($components2[$componentname])) {
            $columnsection = $components2[$componentname];
        } else if (is_object($components2) && isset($components2->$componentname)) {
            $columnsection = $components2->$componentname;
        }

        // Lines 73-78: get elements
        $form_elements = null;
        if (is_array($columnsection) && isset($columnsection['elements'])) {
            $form_elements = $columnsection['elements'];
        } else if (is_object($columnsection) && isset($columnsection->elements)) {
            $form_elements = $columnsection->elements;
        }

        if (!empty($form_elements)) {
            if (!is_array($form_elements)) {
                $form_elements = (array)$form_elements;
            }

            foreach ($form_elements as $element) {
                $elid = null;
                $formdata_inner = null;
                if (is_array($element)) {
                    if (!array_key_exists('id', $element)) continue;
                    $elid = $element['id'];
                    $formdata_inner = $element['formdata'] ?? null;
                } else if (is_object($element)) {
                    if (!isset($element->id)) continue;
                    $elid = $element->id;
                    $formdata_inner = $element->formdata ?? null;
                } else {
                    continue;
                }

                if ((string)$elid !== $componentid) continue;

                if (is_object($formdata_inner) && !empty($formdata_inner->selectedcmids)) {
                    $form_result = (string)$formdata_inner->selectedcmids;
                } elseif (is_array($formdata_inner) && !empty($formdata_inner['selectedcmids'])) {
                    $form_result = (string)$formdata_inner['selectedcmids'];
                }
                break;
            }
        }

        // ===== STEP 3: Simulate set_data($cdata['formdata']) =====
        $formdata = $cdata['formdata'] ?? null;
        $setdata_selectedcmids = '';
        $formdata_type = gettype($formdata);
        if (is_object($formdata) && isset($formdata->selectedcmids)) {
            $setdata_selectedcmids = (string)$formdata->selectedcmids;
        } elseif (is_array($formdata) && isset($formdata['selectedcmids'])) {
            $setdata_selectedcmids = (string)$formdata['selectedcmids'];
        }

        // ===== STEP 4: Get stat_type =====
        $stat_type = '';
        if (is_object($formdata) && isset($formdata->stat_type)) {
            $stat_type = (string)$formdata->stat_type;
        } elseif (is_array($formdata) && isset($formdata['stat_type'])) {
            $stat_type = (string)$formdata['stat_type'];
        }

        // ===== STEP 5: Simulate JS toggleFormRows =====
        $showSelectionFields = in_array($stat_type, [
            'evaluaciones', 'actividades_aprendizaje',
            'contenidos_visualizados', 'recursos_completados'
        ]);

        // ===== STEP 6: Simulate JS countSelected + refreshLabel =====
        // The JS reads the hidden field value. After set_data, it would be $setdata_selectedcmids
        // (set_data overrides the addElement value via updateValue event).
        $effective_cmids = $setdata_selectedcmids;
        $js_count = 0;
        if (!empty($effective_cmids)) {
            $parts = preg_split('/[\s,;]+/', $effective_cmids, -1, PREG_SPLIT_NO_EMPTY);
            $unique = [];
            foreach ($parts as $p) {
                $n = (int)$p;
                if ($n > 0) $unique[$n] = true;
            }
            $js_count = count($unique);
        }

        $js_label = '';
        if ($stat_type === 'evaluaciones') {
            $js_label = $js_count > 0
                ? "Exámenes seleccionados: $js_count"
                : "No tiene exámenes seleccionados. Se usarán todos los visibles.";
        } elseif ($stat_type === 'actividades_aprendizaje') {
            $js_label = $js_count > 0
                ? "Tareas seleccionadas: $js_count"
                : "No tiene tareas seleccionadas. Se usarán todas las visibles.";
        } elseif ($stat_type === 'contenidos_visualizados' || $stat_type === 'recursos_completados') {
            $js_label = $js_count > 0
                ? "Elementos seleccionados: $js_count"
                : "No tiene recursos o actividades seleccionadas. Se usarán todos los visibles.";
        } else {
            $js_label = "(selection fields HIDDEN for stat_type=$stat_type)";
        }

        // ===== STEP 7: Verify cmids exist as course modules =====
        $cmids_exist = 'N/A';
        if ($js_count > 0) {
            $cmid_list = array_keys($unique ?? []);
            list($insql, $params) = $DB->get_in_or_equal($cmid_list);
            $existing = $DB->get_records_select('course_modules', "id $insql", $params, '', 'id, course, module, visible');
            $found = count($existing);
            $cmids_exist = "$found of $js_count cmids exist in course_modules table";

            // Check if they belong to the right course
            $wrong_course = [];
            foreach ($existing as $cm) {
                if ((int)$cm->course !== (int)$rep->courseid) {
                    $wrong_course[] = (int)$cm->id;
                }
            }
            if (!empty($wrong_course)) {
                $cmids_exist .= " WARNING: " . count($wrong_course) . " cmids belong to a DIFFERENT course!";
            }
        }

        $results[] = [
            'report_id' => (int)$rep->id,
            'report_name' => $rep->name,
            'element_cid' => $cid_val,
            'stat_type' => $stat_type,
            'showSelectionFields' => $showSelectionFields,
            'form_get_current_selectedcmids' => $form_result ?: '(empty)',
            'setdata_selectedcmids' => $setdata_selectedcmids ?: '(empty)',
            'effective_hidden_value' => $effective_cmids ?: '(empty)',
            'js_count' => $js_count,
            'js_label' => $js_label,
            'cmids_validation' => $cmids_exist,
            'formdata_type' => $formdata_type,
            'VERDICT' => ($showSelectionFields && $js_count > 0) ? 'OK - Would show selections'
                : ((!$showSelectionFields) ? 'HIDDEN - stat_type hides selection fields'
                : 'PROBLEM - Selection fields shown but no cmids'),
        ];
    }
}

echo "<<<CR_RESULT>>>" . json_encode([
    'ok' => true,
    'total_columns_checked' => count($results),
    'results' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
