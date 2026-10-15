<?php
/**
 * Reparación: renombra campos de formdata de iTOP a nuestro plugin.
 *
 * Campos que renombra:
 *   - stat → stat_type (+ mapea valores: assignment_num_completed_vs_total → actividades_aprendizaje, etc.)
 *   - max_percentage_to_show → maxdisplayvalue
 *   - display_format → displayformat
 *   - link_to_report_with_modal → modalreportid
 *   - pluginname: userstats → userstatsadvanced (si es necesario)
 *
 * SIN --apply: dry-run (solo muestra lo que haría).
 * CON --apply: escribe los cambios en la BD.
 *
 * USO: php repair_itop_field_names.php --config=/var/www/html/moodle/adalid/config.php --courses=604
 *       o --courses=all
 *       [--apply]
 */

$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) $cliargs[$m[1]] = $m[2];
    elseif (preg_match('/^--([^=]+)$/', $token, $m)) $cliargs[$m[1]] = true;
}
if (empty($cliargs['config'])) die("Usage: php script.php --config=/path --courses=604|all [--apply]\n");

$applymode = !empty($cliargs['apply']);

define('CLI_SCRIPT', true);
require($cliargs['config']);
global $CFG, $DB;

$ownlocallib = $CFG->dirroot . '/blocks/configurable_reports/locallib.php';
if (is_readable($ownlocallib)) require_once($ownlocallib);

// --- Field name mapping ---
$FIELD_RENAME = [
    'stat'                       => 'stat_type',
    'max_percentage_to_show'     => 'maxdisplayvalue',
    'display_format'             => 'displayformat',
    'link_to_report_with_modal'  => 'modalreportid',
];

// --- Stat value mapping (iTOP stat values → our stat_type values) ---
$STAT_VALUE_MAP = [
    // Columnas con selección de actividades
    'assignment_num_completed_vs_total'       => 'actividades_aprendizaje',
    'quiz_completed_vs_total_moodle_criteria' => 'evaluaciones',
    'course_modules_completed'                => 'contenidos_visualizados',
    // Otras columnas
    'recursos_visitados_total'                => 'recursos_visitados_total',
    'tiempo_total'                            => 'tiempo_total',
    'nota_final'                              => 'nota_final',
    'nota_media'                              => 'nota_media',
    'primera_conexion'                        => 'primera_conexion',
    'ultima_conexion'                         => 'ultima_conexion',
    'registros'                               => 'registros',
    'dias_conexion'                           => 'dias_conexion',
    'logs_integracion'                        => 'logs_integracion',
    'correos'                                 => 'correos',
    'mensajes_tutor'                          => 'mensajes_tutor',
    'mensajes_alumnos'                        => 'mensajes_alumnos',
    'mensajes_chats'                          => 'mensajes_chats',
    'interacciones_foros'                     => 'interacciones_foros',
    'mensajes_foro'                           => 'mensajes_foro',
    'foros_publicados'                        => 'foros_publicados',
    'ips_utilizadas'                          => 'ips_utilizadas',
    'ultima_ip'                               => 'ultima_ip',
    'intentos_cuestionario'                   => 'intentos_cuestionario',
    'progreso_scorm'                          => 'progreso_scorm',
    'imprimir_informe_detallado'              => 'imprimir_informe_detallado',
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

$log = [];
$stats = ['reports_scanned' => 0, 'columns_scanned' => 0, 'columns_repaired' => 0, 'reports_updated' => 0, 'pluginnames_fixed' => 0];

foreach ($reports as $rep) {
    $stats['reports_scanned']++;
    $components = cr_unserialize($rep->components);
    $modified = false;

    // --- Get elements ref (handle array/object) ---
    $elements_ref = null;
    $cols_is_object = false;
    $components_is_object = is_object($components);

    if ($components_is_object) {
        if (isset($components->columns)) {
            $cols = $components->columns;
            $cols_is_object = is_object($cols);
            if ($cols_is_object && isset($cols->elements)) {
                $elements_ref = &$components->columns->elements;
            } elseif (is_array($cols) && isset($cols['elements'])) {
                $elements_ref = &$components->columns['elements'];
            }
        }
    } else {
        if (isset($components['columns'])) {
            $cols = $components['columns'];
            $cols_is_object = is_object($cols);
            if ($cols_is_object && isset($cols->elements)) {
                $elements_ref = &$components['columns']->elements;
            } elseif (is_array($cols) && isset($cols['elements'])) {
                $elements_ref = &$components['columns']['elements'];
            }
        }
    }

    if (!$elements_ref || (!is_array($elements_ref) && !($elements_ref instanceof \Traversable))) continue;

    // Convert to array for iteration if needed
    if (!is_array($elements_ref)) {
        $elements_ref = (array)$elements_ref;
    }

    foreach ($elements_ref as $idx => &$el) {
        $pn = is_object($el) ? ($el->pluginname ?? '') : ($el['pluginname'] ?? '');
        if (strpos($pn, 'userstats') === false) continue;

        $stats['columns_scanned']++;
        $col_changes = [];
        $elid = is_object($el) ? ($el->id ?? $idx) : ($el['id'] ?? $idx);

        // --- Fix pluginname: userstats → userstatsadvanced ---
        if ($pn === 'userstats') {
            $col_changes[] = "pluginname: userstats → userstatsadvanced";
            if (is_object($el)) {
                $el->pluginname = 'userstatsadvanced';
                // Also fix pluginfullname
                if (isset($el->pluginfullname)) {
                    $el->pluginfullname = str_replace('userstats', 'userstatsadvanced', $el->pluginfullname);
                }
            } else {
                $el['pluginname'] = 'userstatsadvanced';
                if (isset($el['pluginfullname'])) {
                    $el['pluginfullname'] = str_replace('userstats', 'userstatsadvanced', $el['pluginfullname']);
                }
            }
            $stats['pluginnames_fixed']++;
            $modified = true;
        }

        // --- Get formdata ---
        $fd = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);
        if (!$fd) continue;

        $fd_is_object = is_object($fd);

        // --- Rename fields ---
        foreach ($FIELD_RENAME as $old_name => $new_name) {
            $has_old = $fd_is_object ? isset($fd->$old_name) : isset($fd[$old_name]);
            $has_new = $fd_is_object ? isset($fd->$new_name) : isset($fd[$new_name]);

            if ($has_old && !$has_new) {
                $old_val = $fd_is_object ? $fd->$old_name : $fd[$old_name];

                // Map stat values if this is the stat field
                if ($old_name === 'stat' && isset($STAT_VALUE_MAP[(string)$old_val])) {
                    $new_val = $STAT_VALUE_MAP[(string)$old_val];
                    $col_changes[] = "{$old_name}=\"{$old_val}\" → {$new_name}=\"{$new_val}\"";
                } else if ($old_name === 'stat') {
                    // Unknown stat value — copy as-is but log warning
                    $new_val = $old_val;
                    $col_changes[] = "{$old_name}=\"{$old_val}\" → {$new_name}=\"{$old_val}\" (WARNING: unmapped value)";
                } else {
                    $new_val = $old_val;
                    $col_changes[] = "{$old_name} → {$new_name} (value: " . (is_scalar($new_val) ? $new_val : gettype($new_val)) . ")";
                }

                if ($fd_is_object) {
                    $fd->$new_name = $new_val;
                    unset($fd->$old_name);
                } else {
                    $fd[$new_name] = $new_val;
                    unset($fd[$old_name]);
                }
                $modified = true;
            } elseif ($has_old && $has_new) {
                // Both exist — keep new, remove old
                $col_changes[] = "{$old_name} removed (both old and new field exist, keeping {$new_name})";
                if ($fd_is_object) {
                    unset($fd->$old_name);
                } else {
                    unset($fd[$old_name]);
                }
                $modified = true;
            }
        }

        // --- Ensure formdata is stdClass (form.php expects object) ---
        if (!$fd_is_object && is_array($fd)) {
            $fd = (object)$fd;
            $col_changes[] = "formdata converted array → stdClass";
            $modified = true;
        }

        // Write formdata back
        if (is_object($el)) {
            $el->formdata = $fd;
        } else {
            $el['formdata'] = $fd;
        }

        if (!empty($col_changes)) {
            $stats['columns_repaired']++;
            $log[] = [
                'report_id' => (int)$rep->id,
                'report_name' => $rep->name,
                'col_id' => $elid,
                'col_index' => $idx,
                'changes' => $col_changes,
                'applied' => $applymode,
            ];
        }
    }
    unset($el); // break reference

    if ($modified && $applymode) {
        $newcomponents = cr_serialize($components);
        $DB->set_field('block_configurable_reports', 'components', $newcomponents, ['id' => $rep->id]);
        $stats['reports_updated']++;
    } elseif ($modified) {
        $stats['reports_updated']++; // Count as would-update in dry-run
    }
}

$result = [
    'ok' => true,
    'mode' => $applymode ? 'APPLIED' : 'DRY-RUN',
    'stats' => $stats,
    'changes' => $log,
];

echo "<<<CR_RESULT>>>" . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "<<<END_CR_RESULT>>>\n";
