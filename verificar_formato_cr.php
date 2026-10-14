<?php
/**
 * Verificar el formato de serialización de un reporte.
 * Uso: php verificar_formato_cr.php --config=/path/config.php --reportid=10011
 */
$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
}

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB;

$rid = (int)$cliargs['reportid'];
$rec = $DB->get_record('block_configurable_reports', ['id' => $rid]);
if (!$rec) { echo "NOT FOUND\n"; exit(1); }

$raw = $rec->components;
echo "=== Report $rid: {$rec->name} ===\n";
echo "Raw length: " . strlen($raw) . "\n";
echo "First 200 chars: " . substr($raw, 0, 200) . "\n\n";

// Try plain unserialize
$data = @unserialize($raw);
if ($data === false) {
    echo "unserialize() FAILED\n";
    // Try with preprocessing
    $preprocessed = preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $raw);
    $preprocessed = preg_replace('/THEN\+%22(.+?)%22/', 'THEN+%27${1}%27', $preprocessed);
    $preprocessed = preg_replace('/%60/', '+++', $preprocessed);
    $data = @unserialize($preprocessed);
    if ($data === false) {
        echo "preprocessed unserialize() also FAILED\n";
        exit(1);
    }
    echo "preprocessed unserialize() OK\n";
}

echo "Top-level type: " . gettype($data) . "\n";
if (is_object($data)) {
    echo "Top-level class: " . get_class($data) . "\n";
    echo "Top-level properties: " . implode(', ', array_keys(get_object_vars($data))) . "\n";
    if (isset($data->columns)) {
        echo "columns type: " . gettype($data->columns) . "\n";
        if (is_object($data->columns)) {
            echo "columns.elements type: " . gettype($data->columns->elements ?? 'N/A') . "\n";
            if (isset($data->columns->elements) && is_array($data->columns->elements)) {
                echo "columns.elements count: " . count($data->columns->elements) . "\n";
                if (count($data->columns->elements) > 0) {
                    $first = $data->columns->elements[0];
                    echo "First element type: " . gettype($first) . "\n";
                    if (is_object($first)) {
                        echo "First element props: " . implode(', ', array_keys(get_object_vars($first))) . "\n";
                    }
                }
            }
        }
    }
} else if (is_array($data)) {
    echo "TOP LEVEL IS ARRAY - THIS IS THE BUG\n";
    echo "Keys: " . implode(', ', array_keys($data)) . "\n";
}

// Now test what cr_unserialize would produce
require_once($CFG->dirroot . '/blocks/configurable_reports/locallib.php');
$cr_data = cr_unserialize($raw);
echo "\n=== cr_unserialize result ===\n";
echo "Type: " . gettype($cr_data) . "\n";
if (is_array($cr_data)) {
    echo "Keys: " . implode(', ', array_keys($cr_data)) . "\n";
    if (isset($cr_data['columns'])) {
        echo "columns type: " . gettype($cr_data['columns']) . "\n";
    }
}
