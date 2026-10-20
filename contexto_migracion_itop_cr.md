# Migración de Configuración de Plantillas: iTOP → Configurable Reports

**Proyecto:** Despliegue del plugin `block_configurable_reports` en 27 servidores ASPASIA  
**Fecha:** Octubre 2026  
**Autor:** Thimothy Brown (con asistencia de Claude)  
**Propósito:** Documento de contexto técnico para continuación del desarrollo

---

## 1. Panorama general

### Qué estamos haciendo

Estamos reemplazando el plugin de informes alquilado **iTOP** (`block_advanced_reports`) por nuestro propio plugin **Configurable Reports** (`block_configurable_reports`) en las 27 plataformas Moodle de ASPASIA. Esto implica:

1. **Desplegar el plugin CR** en cada servidor.
2. **Instalar las plantillas XML** (8 plantillas de informes predefinidas).
3. **Migrar la configuración personalizada** que cada plataforma tiene en iTOP hacia nuestro plugin — esto incluye las selecciones de actividades/recursos que los administradores configuraron en cada columna de tipo `userstatsadvanced`.
4. **Ocultar o desinstalar iTOP** una vez verificada la migración.

### El problema central

Cuando un administrador configura una columna `userstatsadvanced` (por ejemplo "05 Detalle Evaluaciones"), puede seleccionar qué actividades específicas del curso se incluyen en el cálculo estadístico (qué quizzes, qué tareas, etc.). **iTOP y nuestro plugin almacenan estas selecciones usando sistemas de IDs completamente diferentes**, lo que genera que la migración de valores no funcione correctamente y los checkboxes aparezcan vacíos aunque el label muestre "Elementos seleccionados: N".

---

## 2. Arquitectura de los selectores

### Cómo funciona el selector en iTOP (plugin alquilado)

- Usa el archivo `select_grade_items.php`.
- Muestra ítems de calificación del libro de calificaciones de Moodle.
- **Almacena IDs de `mdl_grade_items`** (tabla del gradebook).
- Los campos en `formdata` se llaman: `cuestionarios`, `tareas`, `scorms`, `quizzes`, `assigns`, etc.

### Cómo funciona el selector en nuestro plugin (CR)

- Usa el archivo `select_course_modules.php`.
- Muestra módulos del curso (actividades y recursos).
- **Almacena IDs de `mdl_course_modules`** (tabla de módulos del curso).
- Todos los IDs seleccionados van en un solo campo: `selectedcmids` (string separado por comas).

### Por qué esto es un problema

Son **espacios de IDs completamente distintos**:

| Concepto | iTOP | Nuestro plugin |
|---|---|---|
| Tabla fuente | `mdl_grade_items` | `mdl_course_modules` |
| Campo en formdata | `cuestionarios`, `tareas`, etc. | `selectedcmids` |
| Tipo de ID | grade_item.id | course_modules.id |
| Ejemplo | Quiz "Examen Final" → grade_item.id = 847 | Quiz "Examen Final" → course_modules.id = 52903 |

Un mismo quiz tiene un ID diferente en cada tabla. Copiar el número `847` de iTOP y ponerlo como cmid en nuestro plugin apuntaría a un módulo completamente diferente (o a ninguno).

---

## 3. Script de migración existente

### `migrar_userstats_itop_cr.php`

Este script PHP (que se ejecuta en cada servidor vía SSH desde el dev server) intenta convertir las selecciones de iTOP a nuestro formato. Su flujo es:

1. **Lee los reportes de iTOP** y los de CR del mismo curso.
2. **Empareja columnas** por nombre de cabecera (ej. "05 Detalle Evaluaciones").
3. **Extrae las selecciones de iTOP** con `extract_itop_selected_items()` — lee los campos `cuestionarios`, `tareas`, `scorms`, etc. del formdata de iTOP.
4. **Convierte IDs** con `instance_ids_to_cmids()`.
5. **Escribe el resultado** en `selectedcmids` del formdata de nuestro plugin.

### El problema de la conversión de IDs

La función `instance_ids_to_cmids()` trata los valores de iTOP como **activity instance IDs** (por ejemplo, `quiz.id`, `assign.id`) y busca en `mdl_course_modules` así:

```php
$cm = $DB->get_record('course_modules', [
    'module' => $mod->id,       // tipo de módulo (quiz, assign, etc.)
    'instance' => $iid,          // el ID que viene de iTOP
    'course' => $courseid
], 'id');
```

**Hipótesis no verificada completamente:** Si iTOP realmente almacena `grade_item.id` en vez de `activity instance.id` (quiz.id), entonces esta conversión es incorrecta. Un `grade_item.id` no es lo mismo que un `quiz.id`:

- `grade_item.id = 847` → es el ID en la tabla `mdl_grade_items`
- `grade_item.iteminstance = 42` → apunta al `quiz.id = 42`
- `course_modules.instance = 42` → también apunta al `quiz.id = 42`

Si los IDs de iTOP son grade_item IDs, la conversión correcta sería:
```
grade_item.id → grade_item.iteminstance → course_modules (WHERE instance = iteminstance AND module = tipo)
```

**TAREA PENDIENTE: Verificar exactamente qué tipo de IDs almacena iTOP** comparando los valores almacenados contra las tablas `mdl_grade_items` y `mdl_course_modules` de un servidor real.

---

## 4. Bugs encontrados y corregidos en el plugin

### Bug 1: Campo hidden sin atributo `id` (CRÍTICO — commit `66e04f8`)

**Archivo:** `form.php` (userstatsadvanced)

**Problema:** Moodle no asigna automáticamente el atributo `id="id_selectedcmids"` a los campos hidden en ciertas versiones/renderers del formulario. Todo el JavaScript del formulario depende de `document.getElementById('id_selectedcmids')`:

- **`toggleFormRows()`** — Habilita/deshabilita el campo hidden según el tipo de estadística.
- **`refreshLabel()`** — Lee el campo hidden para actualizar el label "Elementos seleccionados: N".
- **Botón "Seleccionar cuestionarios"** — Lee el campo hidden para pasar los IDs actuales al popup vía URL.
- **Callback del popup** — Escribe los nuevos IDs seleccionados de vuelta al campo hidden.

Sin el `id`, todos estos fallan silenciosamente: `getElementById` retorna `null`, el popup se abre sin los cmids actuales, y al cerrar el popup no puede escribir los nuevos valores.

**Evidencia:** En la consola del navegador en la página de edición de columna:
```javascript
document.getElementById('id_selectedcmids')        // → null (NO EXISTE)
document.querySelector('input[name="selectedcmids"]') // → <input name="selectedcmids" type="hidden" value="52903">
```

El campo existe con `name` pero no tiene `id`.

**Fix aplicado:**
```php
$mform->addElement('hidden', 'selectedcmids', $currentselectedcmids);
$mform->setType('selectedcmids', PARAM_RAW_TRIMMED);
// Moodle renderer may omit id on hidden fields; JS needs id="id_selectedcmids".
$selectedcmidsEl = $mform->getElement('selectedcmids');
if (method_exists($selectedcmidsEl, 'updateAttributes')) {
    $selectedcmidsEl->updateAttributes(['id' => 'id_selectedcmids']);
}
```

**Estado:** Committeado en el repo del plugin (`66e04f8`), pendiente de desplegar en adalid y verificar.

### Bug 2: `form.php` no manejaba elementos stdClass (commit `fd9e4cd`)

**Archivo:** `form.php`, función `get_current_selected_cmids_from_customdata()`

**Problema:** `cr_unserialize()` devuelve arrays en el nivel superior pero puede preservar objetos stdClass en niveles anidados. La función original solo accedía con sintaxis de array (`$element['id']`), lo que fallaba silenciosamente si algún elemento era stdClass.

**Fix:** Se agregó manejo dual array/object en todos los niveles de acceso dentro de `get_current_selected_cmids_from_customdata()`.

**Estado:** Committeado y desplegado en adalid. Diagnóstico posterior confirmó que todos los 42 elementos son arrays, así que este fix es preventivo.

### Bug 3 (potencial): `array_intersect` filtra cmids inválidos

**Archivo:** `select_course_modules.php`, línea 156

```php
$selectedcmids = userstatsadvanced_popup_parse_selected_cmids($selectedcmidsraw);
if (!empty($selectedcmids)) {
    $selectedcmids = array_values(array_intersect($selectedcmids, array_keys($availablecms)));
}
```

**Comportamiento:** El popup carga los módulos disponibles del curso (`$availablecms`, indexados por cmid) y luego filtra los cmids almacenados contra esa lista. Si los cmids almacenados no corresponden a course_modules reales del curso, `array_intersect` los elimina y **ningún checkbox aparece marcado**.

**Esto no es un bug del código** — es el comportamiento correcto (protege contra IDs inválidos). El problema está upstream: los IDs almacenados deben ser cmids válidos del curso. Si la migración desde iTOP escribió IDs incorrectos, este filtro los descarta.

---

## 5. Diagnósticos creados

### `diagnose_form_rendering.php` + `run_diagnose_form_rendering.py`

Script PHP que simula **exactamente** el flujo que Moodle ejecuta cuando un usuario abre la página de edición de una columna:

1. Simula `editplugin.php`: carga el reporte, hace `cr_unserialize`, busca el elemento por `cid`.
2. Simula `form.php` `get_current_selected_cmids_from_customdata()`: lee selectedcmids del blob.
3. Simula `set_data($cdata['formdata'])`: lee selectedcmids del formdata.
4. Simula el JS `refreshLabel()`: cuenta los cmids y genera el label que el usuario vería.
5. Verifica si los cmids existen en `mdl_course_modules` y si pertenecen al curso correcto.

**Ejecución:**
```bash
cd /ruta/al/repo/scripts
python3 run_diagnose_form_rendering.py
```

Actualmente configurado para correr en adalid, curso 604. La salida JSON indica para cada columna userstatsadvanced:

- `stat_type`: tipo de estadística
- `showSelectionFields`: si el JS mostraría los campos de selección
- `setdata_selectedcmids`: los cmids que se pasarían al formulario
- `js_count`: cuántos cmids se mostrarían en el label
- `cmids_validation`: si los cmids existen en `mdl_course_modules` y son del curso correcto
- `VERDICT`: OK, HIDDEN, o PROBLEM

**TAREA PENDIENTE: Correr este diagnóstico** desde el dev server para obtener datos reales.

### Otros diagnósticos anteriores

| Script | Qué verificó | Resultado |
|---|---|---|
| `diagnose_formdata_fields.php` | ¿Hay campos con nombres de iTOP en el formdata? | No — los 42 columnas tienen campos correctos |
| `diagnose_element_types.php` | ¿Hay elementos stdClass que form.php no pueda leer? | No — todos son arrays |

---

## 6. Flujo completo de la UI (para entender dónde falla)

```
Usuario abre editplugin.php?id=X&comp=columns&cid=Y
    │
    ├── PHP: cr_unserialize($report->components)
    ├── PHP: Encuentra elemento con id == cid
    ├── PHP: Crea formulario, pasa $report como customdata
    ├── PHP: form.php definition()
    │       ├── get_current_selected_cmids_from_customdata() → lee selectedcmids del blob
    │       ├── addElement('hidden', 'selectedcmids', $valor)  ← SIN ID (Bug 1)
    │       └── get_selected_cmids_label_text() → genera "Elementos seleccionados: N"
    ├── PHP: set_data($cdata['formdata']) → sobreescribe valor del hidden con formdata->selectedcmids
    │
    └── Browser renderiza el formulario
        ├── JS DOMContentLoaded: toggleFormRows() → getElementById('id_selectedcmids') → NULL (Bug 1)
        ├── JS DOMContentLoaded: refreshLabel() → getElementById('id_selectedcmids') → NULL (Bug 1)
        │
        └── Usuario hace click en "Seleccionar cuestionarios"
            ├── JS onclick: getElementById('id_selectedcmids') → NULL → selected = '' (Bug 1)
            ├── JS: window.open(popup + '&selectedcmids=')  ← VACÍO por Bug 1
            │
            └── Popup select_course_modules.php
                ├── PHP: selectedcmids viene vacío del URL
                ├── PHP: Lee de la DB como fallback → userstatsadvanced_popup_get_selectedcmids_from_report()
                ├── PHP: Obtiene selectedcmids = "52903" (del blob)
                ├── PHP: array_intersect([52903], array_keys($availablecms))
                │       └── Si 52903 es un cmid válido del curso → lo mantiene → checkbox marcado
                │       └── Si 52903 NO es un cmid válido → intersección vacía → 0 checkboxes
                └── Renderiza checkboxes
```

**Con el fix del Bug 1 (`66e04f8`):** El ID se asigna correctamente, el JS puede leer el campo hidden, y el popup recibe los cmids correctos vía URL. El `array_intersect` de la DB ya no se necesita como fallback.

**Pero si los cmids almacenados son IDs incorrectos** (ej. grade_item IDs de iTOP), el popup los recibiría pero `array_intersect` los filtraría porque no coinciden con ningún course_module real.

---

## 7. Resumen de cambios realizados

### Repo del plugin (`moodle-plugin-reporte-configurable`, branch `master`)

| Commit | Descripción |
|---|---|
| `66e04f8` | **fix:** Asignar explícitamente `id="id_selectedcmids"` al campo hidden para que el JS funcione |
| `fd9e4cd` | **fix:** Manejar elementos stdClass en `get_current_selected_cmids_from_customdata()` |
| `e35a685` | **feat:** Integración bridge iTOP con strip HTML y botones propios |
| `c3087d9` | **feat:** Legacy bridge para datos cacheados de iTOP en plantillas de reporte |

### Repo de scripts (`moodle_phyton_intalacion_reporte_configurable`, branch `main`)

| Commit | Descripción |
|---|---|
| `e4ce9fa` | **chore:** Actualizar inventario.json |
| `d89ddee` | **feat:** Diagnóstico comprehensivo de renderizado del formulario |
| `dc8511a` | **feat:** Diagnóstico de tipos de elementos para bug stdClass |
| `1d5712c` | **feat:** Diagnóstico y reparación de nombres de campos iTOP en formdata |
| `1572cd3` | **fix:** Mover array de actividades a string selectedcmids en reportes corruptos |
| `907823d` | **fix:** Escribir selectedcmids como string separado por comas, no array |
| `05abe69` | **feat:** Scripts de diagnóstico y reparación para corrupción de tipo formdata |
| `57734fb` | **fix:** Preservar tipos array en update_column_selections |
| Anteriores | Varios fixes de mapeo ordinal, diagnósticos de matching, etc. |

---

## 8. Tareas pendientes

### Prioridad alta (bloquean la migración)

1. **Desplegar el fix `66e04f8`** (ID del hidden field) en adalid y verificar que el popup ahora muestra checkboxes correctamente.

2. **Correr `diagnose_form_rendering.php`** en adalid para verificar:
   - ¿Los cmids almacenados (ej. `52903`) existen en `mdl_course_modules`?
   - ¿Pertenecen al curso correcto?
   - Si son inválidos, ¿qué son realmente? (¿grade_item IDs? ¿instance IDs incorrectos?)

3. **Verificar qué tipo de IDs almacena iTOP:** Comparar los valores en los campos `cuestionarios`, `tareas`, etc. del formdata de iTOP contra las tablas `mdl_grade_items` y `mdl_course_modules`. Esto determina si `instance_ids_to_cmids()` es la conversión correcta o si necesita pasar por `grade_items.iteminstance` primero.

4. **Si la conversión es incorrecta**, crear un script de reparación que:
   - Lea los cmids actualmente almacenados en CR.
   - Determine si son grade_item IDs.
   - Los convierta correctamente a course_module IDs.
   - Actualice el blob del reporte.

### Prioridad media

5. **Desplegar en las 26 plataformas restantes** una vez verificado en adalid.

6. **Fix en `bridge.php`**: La constante `EXCLUDED_STATS` necesita actualización.

7. **Fix del mapeo `registros → distinct_days_connection`**: Hay un bug de mapping en el bridge.

8. **Revertir cambio de prueba en ibecon:**
   ```sql
   UPDATE mdl_block_positions SET visible = 1 WHERE id = 6853;
   ```

### Prioridad baja

9. **Deshabilitar `course_reports_navigation`** en las 27 plataformas.

10. **Desinstalar iTOP** (`desinstalar_plugin_alquilado_cr.py`) después de verificar migración completa.

11. **Migrar formato de exportación** (`migrar_exportformat_itop_cr.py`).

12. **Resolver mismatch de columna 002-Progreso** (diferencias entre plantilla iTOP y CR).

---

## 9. Infraestructura y acceso

### Servidores

- **Dev server (jump host):** `ubuntu@ip-172-26-12-46` — desde aquí se ejecutan TODOS los scripts vía SSH. No se puede hacer SSH desde el PC local ni desde contenedores cloud (VPC privada).
- **Adalid (servidor de prueba):** `172.26.28.246`, Moodle en `/var/www/html/moodle/adalid`, usuario web `www-data`.
- **27 plataformas totales:** Listadas en `inventario.json`.

### Repositorios

- **Plugin:** `moodle-plugin-reporte-configurable` (branch `master`)
- **Scripts de despliegue/migración:** `https://github.com/thimothyb/importar-reportes-configurable.git` (branch `main`)

### Credenciales de prueba (adalid)

- Plataforma: `plataforma-adalid.grupoaspasia.com`
- Usuario: `admin-awk-thibrown`
- Clave: `^[sgi<)F)15J36UN`

### Comandos clave (ejecutar desde dev server)

```bash
# Desplegar plugin en un servidor
python3 desplegar_plugin_cr.py

# Instalar plantillas XML
python3 instalar_plantillas_cr.py

# Migrar configuración de userstats desde iTOP
# (PRECAUCIÓN: verificar que la conversión de IDs es correcta primero)
php migrar_userstats_itop_cr.php --config=/var/www/html/moodle/adalid/config.php --courses=604

# Correr diagnóstico de renderizado del formulario
python3 run_diagnose_form_rendering.py

# Ocultar informes iTOP
python3 ocultar_informes_itop_cr.py
```

---

## 10. Glosario rápido

| Término | Significado |
|---|---|
| **cmid** | Course Module ID — ID en la tabla `mdl_course_modules`. Es lo que nuestro plugin usa. |
| **grade_item.id** | ID en la tabla `mdl_grade_items` (libro de calificaciones). Es lo que iTOP posiblemente usa. |
| **instance ID** | El ID de la actividad específica (ej. `quiz.id`, `assign.id`). Es el puente entre grade_items y course_modules. |
| **cr_serialize / cr_unserialize** | Funciones propias del plugin. `cr_serialize` = `serialize(urlencode_recursive($data))`. `cr_unserialize` = `unserialize()` + `urldecode_recursive()` + cast a array solo en el nivel superior. |
| **formdata** | Objeto/array dentro de cada elemento de columna que contiene la configuración del formulario (stat_type, selectedcmids, etc.). |
| **components blob** | Campo `components` del registro del reporte en `mdl_block_configurable_reports`. Contiene toda la configuración serializada. |
| **stat_type** | Tipo de estadística de la columna. Solo 4 valores muestran los campos de selección: `evaluaciones`, `actividades_aprendizaje`, `contenidos_visualizados`, `recursos_completados`. |
