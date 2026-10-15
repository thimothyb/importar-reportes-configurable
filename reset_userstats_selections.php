<?php
/**
 * Limpia selectedcmids y actividades de columnas userstatsadvanced
 * en nuestro plugin para un curso dado, permitiendo re-migrar.
 *
 * USO: php script.php --config=/path/config.php --courseid=604 [--apply]
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/s', $token, $m)) {
        $cliargs[$m[1]] = $m[2] ?? true;
    }
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courseid=604 [--apply]\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$courseid = (int)($cliargs['courseid'] ?? 604);
$apply = isset($cliargs['apply']);

function decode_comp($raw) {
    if (empty($raw)) return null;
    if (function_exists('cr_unserialize')) return cr_unserialize($raw);
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    return @unserialize($raw2) ?: null;
}

function deep_to_array($v) {
    if (is_object($v)) $v = (array)$v;
    if (is_array($v)) return array_map('deep_to_array', $v);
    return $v;
}

$own_table = 'block_configurable_reports';
$field = $DB->get_manager()->field_exists($own_table, 'courseid') ? 'courseid' : 'course';
$reports = $DB->get_records($own_table, [$field => $courseid], 'id ASC');

$results = [];
$total_cleaned = 0;

foreach ($reports as $rep) {
    $comp = decode_comp($rep->components);
    if (!$comp) continue;
    $data = deep_to_array((array)$comp);
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $elements = $cols['elements'] ?? [];
    $changed = false;

    foreach ($elements as $idx => &$el) {
        if (strpos($el['pluginname'] ?? '', 'userstats') === false) continue;
        $fd = &$el['formdata'];
        if (!is_array($fd)) continue;

        $header = $fd['columname'] ?? '';
        $had_selectedcmids = !empty($fd['selectedcmids'] ?? '');
        $had_actividades = !empty($fd['actividades'] ?? []);

        if ($had_selectedcmids || $had_actividades) {
            $info = [
                'report' => $rep->name,
                'header' => $header,
                'old_selectedcmids' => $fd['selectedcmids'] ?? '',
                'old_actividades_count' => is_array($fd['actividades'] ?? null) ? count($fd['actividades']) : 0,
            ];

            if ($apply) {
                unset($fd['selectedcmids']);
                unset($fd['actividades']);
                $changed = true;
                $info['action'] = 'cleaned';
            } else {
                $info['action'] = 'would_clean';
            }

            $results[] = $info;
            $total_cleaned++;
        }
    }
    unset($el, $fd);

    if ($changed && $apply) {
        $data['columns']['elements'] = $elements;
        if (function_exists('cr_serialize')) {
            $serialized = cr_serialize($data);
        } else {
            $serialized = serialize((object)$data);
        }
        $DB->set_field($own_table, 'components', $serialized, ['id' => $rep->id]);
    }
}

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "<<<CR_RESULT>>>" . json_encode([
    'ok' => true,
    'mode' => $mode,
    'courseid' => $courseid,
    'total_cleaned' => $total_cleaned,
    'details' => $results,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
