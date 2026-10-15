<?php
/**
 * Mueve el campo 'actividades' (array) a 'selectedcmids' (string comma-separated)
 * en formdata de columnas userstatsadvanced.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/s', $token, $m)) $cliargs[$m[1]] = $m[2] ?? true;
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --reportids=10037,10041 [--apply]\n");
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;
$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

$apply = isset($cliargs['apply']);
$ids = array_map('intval', explode(',', $cliargs['reportids'] ?? '10037,10041'));

$results = [];
foreach ($ids as $rid) {
    $rep = $DB->get_record('block_configurable_reports', ['id' => $rid]);
    if (!$rep) { $results[] = ['id' => $rid, 'status' => 'not_found']; continue; }

    $comp = cr_unserialize((string)$rep->components);
    if (!is_array($comp) || !isset($comp['columns']['elements'])) {
        $results[] = ['id' => $rid, 'status' => 'no_elements']; continue;
    }

    $changed = 0;
    foreach ($comp['columns']['elements'] as $i => &$el) {
        $pn = $el['pluginname'] ?? '';
        if (strpos($pn, 'userstats') === false) continue;

        $fd = $el['formdata'] ?? null;
        if (!$fd || !is_object($fd)) continue;

        if (isset($fd->actividades) && !empty($fd->actividades)) {
            $cmids = $fd->actividades;
            if (is_array($cmids)) {
                $str = implode(',', array_map('intval', $cmids));
            } else {
                $str = (string)$cmids;
            }
            $fd->selectedcmids = $str;
            unset($fd->actividades);
            $el['formdata'] = $fd;
            $changed++;
            $results[] = ['id' => $rid, 'index' => $i, 'plugin' => $pn,
                          'selectedcmids' => $str, 'action' => $apply ? 'fixed' : 'would_fix'];
        }
    }
    unset($el);

    if ($changed > 0 && $apply) {
        $new_raw = cr_serialize($comp);
        $DB->set_field('block_configurable_reports', 'components', $new_raw, ['id' => $rid]);
    }
}

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'apply' => $apply, 'results' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
