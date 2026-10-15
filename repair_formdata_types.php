<?php
/**
 * Repara reportes cuyo formdata fue convertido a array por deep_to_array.
 * Convierte formdata de vuelta a stdClass en cada elemento de columns,
 * conditions, filters, ordering, y permissions.
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courseid=604                (obligatorio)
 *   --reportids=10037,10041       (opcional: solo estos reportes; si no, todos del curso)
 *   --apply                       (opcional: sin este flag solo hace dry-run)
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/s', $token, $m)) {
        $cliargs[$m[1]] = $m[2] ?? true;
    }
}
if (empty($cliargs['config'])) {
    die("Usage: php repair_formdata_types.php --config=/path --courseid=604 [--reportids=10037,10041] [--apply]\n");
}
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

if (!function_exists('cr_unserialize') || !function_exists('cr_serialize')) {
    die("cr_unserialize/cr_serialize not found\n");
}

$courseid = (int)($cliargs['courseid'] ?? 604);
$apply = isset($cliargs['apply']);
$field = $DB->get_manager()->field_exists('block_configurable_reports', 'courseid') ? 'courseid' : 'course';

if (!empty($cliargs['reportids'])) {
    $ids = array_map('intval', explode(',', $cliargs['reportids']));
    list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'rid');
    $params['cid'] = $courseid;
    $reports = $DB->get_records_select('block_configurable_reports',
        "$field = :cid AND id $insql", $params, 'id ASC');
} else {
    $reports = $DB->get_records('block_configurable_reports', [$field => $courseid], 'id ASC');
}

/**
 * Convierte arrays asociativos de vuelta a stdClass SOLO en los campos
 * formdata y summary de cada elemento. No toca los niveles superiores.
 */
function ensure_formdata_is_object(&$elements_arr) {
    $fixed = 0;
    foreach ($elements_arr as $idx => &$el) {
        // formdata
        if (is_array($el)) {
            if (isset($el['formdata']) && is_array($el['formdata'])) {
                $el['formdata'] = (object) $el['formdata'];
                $fixed++;
            }
            // summary (plugin_userfield::summary expects object)
            if (isset($el['summary']) && is_array($el['summary'])) {
                $el['summary'] = (object) $el['summary'];
                $fixed++;
            }
        } elseif (is_object($el)) {
            if (isset($el->formdata) && is_array($el->formdata)) {
                $el->formdata = (object) $el->formdata;
                $fixed++;
            }
            if (isset($el->summary) && is_array($el->summary)) {
                $el->summary = (object) $el->summary;
                $fixed++;
            }
        }
    }
    unset($el);
    return $fixed;
}

$results = [];
foreach ($reports as $rep) {
    $comp = cr_unserialize((string)$rep->components);
    if (!$comp || !is_array($comp)) {
        $results[] = ['id' => (int)$rep->id, 'name' => $rep->name, 'status' => 'skip_no_components'];
        continue;
    }

    $total_fixed = 0;
    // Process all component sections that have elements
    $sections = ['columns', 'conditions', 'filters', 'ordering', 'permissions',
                 'Columns', 'Conditions', 'Filters', 'Ordering', 'Permissions'];

    foreach ($sections as $section) {
        if (!isset($comp[$section])) continue;
        $sec = $comp[$section];

        // Get elements from section
        if (is_array($sec) && isset($sec['elements']) && is_array($sec['elements'])) {
            $fixed = ensure_formdata_is_object($sec['elements']);
            if ($fixed > 0) {
                $comp[$section] = $sec;
                $total_fixed += $fixed;
            }
        } elseif (is_object($sec) && isset($sec->elements)) {
            $els = is_object($sec->elements) ? (array)$sec->elements : $sec->elements;
            if (is_array($els)) {
                $fixed = ensure_formdata_is_object($els);
                if ($fixed > 0) {
                    $sec->elements = $els;
                    $comp[$section] = $sec;
                    $total_fixed += $fixed;
                }
            }
        }
    }

    $info = ['id' => (int)$rep->id, 'name' => $rep->name, 'fixes' => $total_fixed];

    if ($total_fixed > 0 && $apply) {
        $new_raw = cr_serialize($comp);
        $DB->set_field('block_configurable_reports', 'components', $new_raw, ['id' => $rep->id]);
        $info['applied'] = true;
    } elseif ($total_fixed > 0) {
        $info['applied'] = false;
        $info['note'] = 'dry-run, use --apply to save';
    } else {
        $info['note'] = 'no fixes needed';
    }

    $results[] = $info;
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'apply' => $apply, 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
