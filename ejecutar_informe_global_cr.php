<?php
/**
 * CLI para EJECUTAR el reporte "01 Informe Global" de ambos plugins y
 * comparar los datos resultantes (la tabla que ve el usuario).
 *
 * Usa la API interna de cada plugin para instanciar el reporte y
 * ejecutarlo, capturando cabeceras y filas.
 *
 * Parámetros:
 *   --config=/ruta/a/config.php   (obligatorio)
 *   --courses=3,4,6               (obligatorio: ids separados por coma)
 *   --report=01 Informe Global    (opcional: nombre del reporte, def: "01 Informe Global")
 *   --maxrows=100                 (opcional: máx filas a capturar, def: 200)
 *
 * @package   block_configurable_reports
 * @copyright 2026 Awakelab
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$cliargs = [];
foreach (array_slice($argv, 1) as $token) {
    if (preg_match('/^--([^=]+)=(.*)$/s', $token, $m)) {
        $cliargs[$m[1]] = $m[2];
    } else if (preg_match('/^--([^=]+)$/', $token, $m)) {
        $cliargs[$m[1]] = true;
    }
}

function cr_emit(array $payload, int $exitcode): void {
    $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE;
    $json = json_encode($payload, $flags);
    if ($json === false) {
        $payload = json_decode(json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE), true) ?? $payload;
        $json = json_encode($payload, $flags);
    }
    if ($json === false) {
        $json = json_encode(['ok' => false, 'fatal' => 'json_encode falló: ' . json_last_error_msg()], $flags);
    }
    fwrite(STDOUT, "<<<CR_RESULT>>>" . $json . "<<<END_CR_RESULT>>>\n");
    exit($exitcode);
}

if (empty($cliargs['config']) || !is_readable($cliargs['config'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --config con la ruta a config.php válida'], 2);
}
if (empty($cliargs['courses'])) {
    cr_emit(['ok' => false, 'fatal' => 'Falta --courses (ids separados por coma)'], 2);
}

$courseids = array_filter(array_map('intval', explode(',', $cliargs['courses'])));
$targetreport = $cliargs['report'] ?? '01 Informe Global';
$maxrows = isset($cliargs['maxrows']) ? (int) $cliargs['maxrows'] : 200;

define('CLI_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);
require($cliargs['config']);
global $CFG, $DB, $USER;

// Necesitamos un usuario admin para ejecutar los reportes.
$USER = $DB->get_record('user', ['id' => 2]); // admin
if (!$USER) {
    $USER = $DB->get_record('user', ['username' => 'admin']);
}
if (!$USER) {
    // Tomar el primer admin del sitio.
    $admins = get_admins();
    $USER = reset($admins);
}

// ---------------------------------------------------------------------------
// Cargar las clases del plugin propio
// ---------------------------------------------------------------------------
$owndir = $CFG->dirroot . '/blocks/configurable_reports';
$ownlocallib = $owndir . '/locallib.php';
$ownreportclass = $owndir . '/report.class.php';

if (!is_readable($ownlocallib)) {
    cr_emit(['ok' => false, 'fatal' => 'No se encontró locallib.php del plugin propio'], 2);
}

require_once($ownlocallib);

// Cargar clase base de reportes si existe.
$has_report_class = false;
if (is_readable($ownreportclass)) {
    require_once($ownreportclass);
    $has_report_class = true;
}

// ---------------------------------------------------------------------------
// Detectar tabla del plugin alquilado
// ---------------------------------------------------------------------------
$dbman = $DB->get_manager();
$rentedtablename = '';
$candidates = ['block_advanced_reports', 'block_adv_reports', 'block_advreports'];
foreach ($candidates as $tname) {
    if ($dbman->table_exists($tname)) {
        $rentedtablename = $tname;
        break;
    }
}
if (empty($rentedtablename)) {
    try {
        $prefix = $CFG->prefix;
        $alltables = $DB->get_records_sql(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?",
            ["{$prefix}%adv%report%"]
        );
        if ($alltables) {
            foreach ($alltables as $t) {
                $rentedtablename = str_replace($prefix, '', $t->table_name);
                break;
            }
        }
    } catch (Throwable $e) {
        // Ignorar.
    }
}

// Detectar directorio del plugin alquilado.
$renteddir = $CFG->dirroot . '/blocks/advanced_reports';
$rentedlocallib = $renteddir . '/locallib.php';
$has_rented_classes = false;

// ---------------------------------------------------------------------------
// Función para ejecutar un reporte usando la API del plugin
// ---------------------------------------------------------------------------
function execute_report_via_api($reportrecord, string $plugindir, int $courseid, int $maxrows): array {
    global $CFG, $DB, $USER, $PAGE, $OUTPUT;

    // Asegurar que PAGE está configurado.
    if (!isset($PAGE) || !$PAGE) {
        $PAGE = new moodle_page();
    }
    try {
        $PAGE->set_context(\context_course::instance($courseid));
    } catch (Throwable $e) {
        // Ignorar si falla.
    }

    $type = $reportrecord->type ?? 'users';
    $reportclassfile = $plugindir . '/reports/' . $type . '/report.class.php';

    if (!is_readable($reportclassfile)) {
        return ['error' => "Archivo de clase no encontrado: reports/{$type}/report.class.php"];
    }

    try {
        require_once($plugindir . '/report.class.php');
        require_once($reportclassfile);

        // La clase se llama report_TYPE (ej: report_users).
        $classname = "report_{$type}";
        if (!class_exists($classname)) {
            return ['error' => "Clase {$classname} no existe"];
        }

        $reportobj = new $classname($reportrecord);

        // Ejecutar el reporte.
        $reportobj->create_report();

        // Capturar datos del reporte.
        $headers = [];
        $rows = [];

        if (isset($reportobj->finalreport) && is_object($reportobj->finalreport)) {
            $fr = $reportobj->finalreport;
            $headers = isset($fr->head) ? (array) $fr->head : [];
            $rawrows = isset($fr->data) ? (array) $fr->data : [];

            $count = 0;
            foreach ($rawrows as $row) {
                if ($count >= $maxrows) break;
                // Limpiar HTML de cada celda para quedarnos con el texto plano.
                $cleanrow = [];
                foreach ((array) $row as $cell) {
                    $text = is_string($cell) ? strip_tags($cell) : (string) $cell;
                    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
                    $text = trim(preg_replace('/\s+/', ' ', $text));
                    $cleanrow[] = $text;
                }
                $rows[] = $cleanrow;
                $count++;
            }
        } else if (isset($reportobj->finalreport) && is_array($reportobj->finalreport)) {
            // Algunos reportes devuelven un array.
            return ['error' => 'Formato de reporte no esperado (array)', 'raw_type' => gettype($reportobj->finalreport)];
        } else {
            return ['error' => 'finalreport no disponible después de create_report()'];
        }

        return [
            'error' => null,
            'headers' => $headers,
            'rows' => $rows,
            'row_count' => count($rows),
            'total_in_report' => isset($fr->data) ? count((array) $fr->data) : 0,
        ];

    } catch (Throwable $e) {
        return ['error' => $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine()];
    }
}

// ---------------------------------------------------------------------------
// Función alternativa: ejecutar columna por columna manualmente
// ---------------------------------------------------------------------------
function execute_report_manual($reportrecord, int $courseid, int $maxrows): array {
    global $DB;

    // Deserializar componentes.
    $components = cr_unserialize($reportrecord->components);
    if (!is_array($components)) {
        return ['error' => 'No se pudo deserializar components'];
    }

    $columns = $components['columns'] ?? [];
    $elements = $columns['elements'] ?? [];
    $conditions = $components['conditions'] ?? [];

    // Obtener usuarios del curso según las condiciones.
    $context = \context_course::instance($courseid);
    $enrolled = get_enrolled_users($context, '', 0, 'u.*', 'u.lastname, u.firstname', 0, $maxrows);

    if (empty($enrolled)) {
        return ['error' => null, 'headers' => [], 'rows' => [], 'row_count' => 0, 'note' => 'Sin usuarios matriculados'];
    }

    // Extraer cabeceras de las columnas.
    $headers = [];
    foreach ($elements as $el) {
        $formdata = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);
        if (is_object($formdata)) {
            $headers[] = $formdata->columname ?? '?';
        } else if (is_array($formdata)) {
            $headers[] = $formdata['columname'] ?? '?';
        } else {
            $headers[] = '?';
        }
    }

    // Para cada usuario, construir una fila con datos básicos que podemos obtener.
    $rows = [];
    foreach ($enrolled as $user) {
        $row = [];
        foreach ($elements as $el) {
            $formdata = is_object($el) ? ($el->formdata ?? null) : ($el['formdata'] ?? null);
            if (is_object($formdata)) {
                $fd = $formdata;
            } else if (is_array($formdata)) {
                $fd = (object) $formdata;
            } else {
                $row[] = '?';
                continue;
            }

            $pluginname = '';
            if (is_object($el)) {
                $pluginname = $el->pluginname ?? '';
            } else {
                $pluginname = $el['pluginname'] ?? '';
            }

            // Resolver valor según el tipo de plugin.
            $value = resolve_column_value($user, $fd, $pluginname, $courseid);
            $row[] = $value;
        }
        $rows[] = $row;
    }

    return [
        'error' => null,
        'headers' => $headers,
        'rows' => $rows,
        'row_count' => count($rows),
        'method' => 'manual',
    ];
}

/**
 * Resuelve el valor de una columna para un usuario.
 */
function resolve_column_value($user, $fd, string $pluginname, int $courseid): string {
    global $DB;

    // Campos del perfil de usuario.
    if ($pluginname === 'userfield' || $pluginname === 'coursecustomfield') {
        $column = $fd->column ?? $fd->field ?? '';
        if ($column === 'fullname' || $column === 'firstname') {
            return $user->firstname ?? '';
        }
        if ($column === 'lastname') {
            return $user->lastname ?? '';
        }
        if ($column === 'username') {
            return $user->username ?? '';
        }
        if ($column === 'email') {
            return $user->email ?? '';
        }
        if (isset($user->$column)) {
            return (string) $user->$column;
        }
        return '';
    }

    // Para plugins de estadísticas, solo podemos indicar el tipo.
    $stat_type = $fd->stat_type ?? $fd->stat ?? '';
    return "[{$pluginname}:{$stat_type}]";
}

// ---------------------------------------------------------------------------
// Procesar cursos
// ---------------------------------------------------------------------------
$output = [];

foreach ($courseids as $courseid) {
    $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
    if (!$course) {
        $output[] = ['courseid' => $courseid, 'error' => 'Curso no encontrado'];
        continue;
    }

    $coursedata = [
        'courseid' => $courseid,
        'fullname' => $course->fullname,
        'shortname' => $course->shortname,
    ];

    // Buscar reporte "01 Informe Global" en el plugin propio.
    $ownreport = $DB->get_record('block_configurable_reports', [
        'courseid' => $courseid,
        'name' => $targetreport,
    ]);

    if ($ownreport) {
        // Intentar ejecutar via API del plugin.
        $ownresult = execute_report_via_api($ownreport, $owndir, $courseid, $maxrows);
        if (!empty($ownresult['error']) && strpos($ownresult['error'], 'not found') !== false) {
            // Fallback: ejecución manual.
            $ownresult = execute_report_manual($ownreport, $courseid, $maxrows);
        }
        $coursedata['own'] = $ownresult;
        $coursedata['own']['report_id'] = (int) $ownreport->id;
    } else {
        $coursedata['own'] = ['error' => "Reporte '{$targetreport}' no encontrado en plugin propio"];
    }

    // Buscar reporte en el plugin alquilado.
    if (!empty($rentedtablename) && $dbman->table_exists($rentedtablename)) {
        // Detectar campo de curso.
        $samplerow = $DB->get_records_sql("SELECT * FROM {{$rentedtablename}} LIMIT 1");
        $columns_info = $samplerow ? array_keys((array) reset($samplerow)) : [];
        $coursefield = in_array('courseid', $columns_info) ? 'courseid' : null;
        if (!$coursefield && in_array('course', $columns_info)) {
            $coursefield = 'course';
        }

        $rentedreport = null;
        if ($coursefield) {
            // Buscar por nombre exacto.
            $allrented = $DB->get_records($rentedtablename, [$coursefield => $courseid]);
            foreach ($allrented as $r) {
                $name = $r->name ?? $r->title ?? $r->reportname ?? '';
                if ($name === $targetreport) {
                    $rentedreport = $r;
                    break;
                }
            }
        }

        if ($rentedreport) {
            // El plugin alquilado podría tener su propia clase de reportes.
            $rentedresult = null;
            if (is_dir($renteddir) && is_readable($renteddir . '/report.class.php')) {
                // Intentar con las clases del alquilado.
                $rentedresult = execute_report_via_api($rentedreport, $renteddir, $courseid, $maxrows);
            }
            if ($rentedresult === null || !empty($rentedresult['error'])) {
                // Fallback: usar las clases del propio (mismo esquema de datos).
                $rentedresult_via_own = execute_report_via_api($rentedreport, $owndir, $courseid, $maxrows);
                if (empty($rentedresult_via_own['error'])) {
                    $rentedresult = $rentedresult_via_own;
                    $rentedresult['note'] = 'Ejecutado con clases del plugin propio';
                } else {
                    // Último fallback: ejecución manual.
                    $rentedresult = execute_report_manual($rentedreport, $courseid, $maxrows);
                }
            }
            $coursedata['rented'] = $rentedresult;
            $coursedata['rented']['report_id'] = (int) $rentedreport->id;
        } else {
            $coursedata['rented'] = ['error' => "Reporte '{$targetreport}' no encontrado en plugin alquilado"];
        }
    } else {
        $coursedata['rented'] = ['error' => 'Tabla del plugin alquilado no encontrada'];
    }

    // Comparar datos si ambos tienen resultado.
    if (empty($coursedata['own']['error']) && empty($coursedata['rented']['error'])) {
        $own_rows = $coursedata['own']['rows'] ?? [];
        $rented_rows = $coursedata['rented']['rows'] ?? [];
        $own_headers = $coursedata['own']['headers'] ?? [];
        $rented_headers = $coursedata['rented']['headers'] ?? [];

        $comparison = [
            'own_rows' => count($own_rows),
            'rented_rows' => count($rented_rows),
            'same_row_count' => count($own_rows) === count($rented_rows),
            'own_headers' => $own_headers,
            'rented_headers' => $rented_headers,
            'headers_identical' => $own_headers === $rented_headers,
        ];

        // Comparar fila por fila (por posición).
        if (count($own_rows) === count($rented_rows) && count($own_rows) > 0) {
            $identical_rows = 0;
            $different_rows = [];
            for ($i = 0; $i < count($own_rows); $i++) {
                if ($own_rows[$i] === $rented_rows[$i]) {
                    $identical_rows++;
                } else {
                    $diff_cells = [];
                    $maxcols = max(count($own_rows[$i]), count($rented_rows[$i]));
                    for ($j = 0; $j < $maxcols; $j++) {
                        $ov = $own_rows[$i][$j] ?? '(vacío)';
                        $rv = $rented_rows[$i][$j] ?? '(vacío)';
                        if ($ov !== $rv) {
                            $header = $own_headers[$j] ?? $rented_headers[$j] ?? "col{$j}";
                            $diff_cells[] = [
                                'column' => $header,
                                'own' => $ov,
                                'rented' => $rv,
                            ];
                        }
                    }
                    if (!empty($diff_cells)) {
                        $different_rows[] = ['row' => $i, 'diffs' => $diff_cells];
                    }
                }
            }
            $comparison['identical_rows'] = $identical_rows;
            $comparison['different_row_details'] = $different_rows;
            $comparison['data_identical'] = empty($different_rows);
        }

        $coursedata['comparison'] = $comparison;
    }

    $output[] = $coursedata;
}

cr_emit([
    'ok' => true,
    'target_report' => $targetreport,
    'rented_table' => $rentedtablename ?: null,
    'courses' => $output,
], 0);
