<?php
/**
 * Scrape iTOP's select_grade_items popup to capture actual checked selections.
 * Runs on the server, uses Moodle's internal session to load the popup HTML.
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}
if (empty($cliargs['config'])) {
    die("Usage: php scrape_itop_selections.php --config=/path/config.php --courseid=604 --user=admin-awk-thibrown --pass=PASSWORD\n");
}
define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$courseid = (int)($cliargs['courseid'] ?? 604);
$username = $cliargs['user'] ?? '';
$password = $cliargs['pass'] ?? '';

if (empty($username) || empty($password)) {
    die("Need --user and --pass\n");
}

// Find rented table
$rentedtable = null;
foreach (['block_advanced_reports','block_rented_reports'] as $t) {
    if ($DB->get_manager()->table_exists($t)) { $rentedtable = $t; break; }
}
if (!$rentedtable) { echo json_encode(['error' => 'no rented table']); exit; }

$field = $DB->get_manager()->field_exists($rentedtable, 'courseid') ? 'courseid' : 'course';
$itop_reports = $DB->get_records($rentedtable, [$field => $courseid], 'id ASC');

// Decode components helper
function decode_comp($raw) {
    if (empty($raw)) return null;
    $raw2 = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $d = @unserialize($raw2);
    return $d !== false ? $d : null;
}

// Login via curl and get session cookie
$login_url = $CFG->wwwroot . '/login/index.php';
$cookiefile = tempnam(sys_get_temp_dir(), 'moodle_cookie_');

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $login_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookiefile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookiefile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$html = curl_exec($ch);

// Get logintoken
preg_match('/name="logintoken"\s+value="([^"]+)"/', $html, $m);
$logintoken = $m[1] ?? '';

// POST login
curl_setopt($ch, CURLOPT_URL, $login_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => $username,
    'password' => $password,
    'logintoken' => $logintoken,
]));
$html = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Check if logged in
curl_setopt($ch, CURLOPT_URL, $CFG->wwwroot . '/my/');
curl_setopt($ch, CURLOPT_POST, false);
$html = curl_exec($ch);
$logged_in = strpos($html, 'logout') !== false || strpos($html, 'loggedin') !== false;

if (!$logged_in) {
    echo json_encode(['error' => 'login failed', 'http_code' => $httpcode]);
    @unlink($cookiefile);
    exit;
}

$results = [];

foreach ($itop_reports as $rep) {
    $comp = decode_comp($rep->components);
    if (!$comp) continue;
    $data = (array)$comp;
    $cols = $data['columns'] ?? null;
    if (!$cols) continue;
    $cols = is_object($cols) ? (array)$cols : $cols;
    $elements = $cols['elements'] ?? [];
    if (is_object($elements)) $elements = (array)$elements;

    foreach ($elements as $idx => $el) {
        $el = is_object($el) ? (array)$el : $el;
        $pn = $el['pluginname'] ?? '';
        if (strpos($pn, 'userstats') === false) continue;
        $fd = $el['formdata'] ?? $el;
        $fd = is_object($fd) ? (array)$fd : $fd;

        // Check if has ids
        $ids_raw = $fd['ids'] ?? '';
        if (empty($ids_raw)) continue;

        $header = $fd['columname'] ?? $fd['column_name'] ?? '';
        $stat = $fd['stat'] ?? $fd['stat_type'] ?? '';

        // Fetch the popup
        $popup_url = $CFG->wwwroot . '/blocks/advanced_reports/components/columns/userstats/select_grade_items.php'
            . '?courseid=' . $courseid . '&reportid=' . $rep->id . '&cid=' . $idx;

        curl_setopt($ch, CURLOPT_URL, $popup_url);
        curl_setopt($ch, CURLOPT_POST, false);
        $popup_html = curl_exec($ch);
        $popup_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Parse checked checkboxes - look for checked inputs
        $checked_cmids = [];
        // Pattern: <input type="checkbox" name="something" value="CMID" checked
        if (preg_match_all('/<input[^>]*type=["\']checkbox["\'][^>]*value=["\'](\d+)["\'][^>]*checked/i', $popup_html, $matches)) {
            $checked_cmids = array_map('intval', $matches[1]);
        }
        // Also try reverse order: checked before value
        if (preg_match_all('/<input[^>]*checked[^>]*value=["\'](\d+)["\'][^>]*type=["\']checkbox["\'][^>]*/i', $popup_html, $matches2)) {
            $checked_cmids = array_merge($checked_cmids, array_map('intval', $matches2[1]));
        }
        // Another pattern: value before checked, type first
        if (preg_match_all('/<input[^>]*type=["\']checkbox["\'][^>]*value=["\'](\d+)["\'][^>]*/i', $popup_html, $all_cb)) {
            // Check which have 'checked' in the full match
            foreach ($all_cb[0] as $i => $full_match) {
                if (stripos($full_match, 'checked') !== false) {
                    $val = (int)$all_cb[1][$i];
                    if (!in_array($val, $checked_cmids)) $checked_cmids[] = $val;
                }
            }
        }

        $checked_cmids = array_values(array_unique($checked_cmids));
        sort($checked_cmids);

        // Also get ALL checkboxes (checked + unchecked) for context
        $all_cmids = [];
        if (preg_match_all('/<input[^>]*type=["\']checkbox["\'][^>]*value=["\'](\d+)["\'][^>]*/i', $popup_html, $all_matches)) {
            $all_cmids = array_map('intval', $all_matches[1]);
        }
        $all_cmids = array_values(array_unique($all_cmids));
        sort($all_cmids);

        $results[] = [
            'report' => $rep->name,
            'col_index' => $idx,
            'header' => $header,
            'stat' => $stat,
            'ids_stored' => is_string($ids_raw) ? $ids_raw : json_encode($ids_raw),
            'popup_http_code' => $popup_code,
            'popup_html_length' => strlen($popup_html),
            'all_checkbox_cmids' => $all_cmids,
            'checked_cmids' => $checked_cmids,
            'checked_count' => count($checked_cmids),
            'total_count' => count($all_cmids),
        ];
    }
}

curl_close($ch);
@unlink($cookiefile);

echo "<<<CR_RESULT>>>" . json_encode(['ok' => true, 'selections' => $results],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
