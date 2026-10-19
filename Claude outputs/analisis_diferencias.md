# Análisis de Diferencias: Configurable Reports vs iTOP (Curso 601, Adalid)

## Resumen ejecutivo

La comparación profunda de los 8 reportes emparejados del curso 601 revela que **las plantillas no son idénticas** — hay diferencias más allá del campo `stat` que ya maneja el script de migración. A continuación se categorizan todas las diferencias encontradas, separando las que son cosméticas/esperadas de las que requieren acción.

---

## CATEGORÍA 1: Cosméticas / Esperadas (NO requieren acción)

Estas diferencias son inherentes a que son plugins distintos y no afectan la funcionalidad:

| Diferencia | Nuestro plugin | iTOP | Motivo |
|---|---|---|---|
| `id` de elementos | IDs aleatorios propios | IDs aleatorios diferentes | Cada plugin genera sus propios IDs internos |
| `pluginfullname` | Nombre display nuestro | Nombre display iTOP | Solo afecta la UI de admin |
| `submitbutton` vs `submibutton` | `submitbutton` | `submibutton` (typo) | Error tipográfico en iTOP |
| `format: "auto"` | Presente | Ausente | Campo extra en nuestro plugin |
| `showadvanced_last: "0"` | Ausente | Presente | Campo extra de iTOP |
| Prefijo del nombre | `001-Informe Global` | `01 Informe Global` | Convención de numeración diferente |

**Decisión:** Ignorar estas diferencias. No afectan el resultado visible para el usuario final.

---

## CATEGORÍA 2: Ya manejadas por el script de migración de stats

El script `migrar_stats_itop_cr.php` ya corrige estas:

- **12 columnas** en reportes 002-Progreso y 005-Detalle Evaluaciones
- Convierte el formato `stat` de modulestats (ej: `quiz_score_id3577`) al formato scormadvancedgrades (`quiz:52590:score`)
- Resuelve correctamente la conversión instance_id → cmid via `course_modules`

**Decisión:** Aplicar la migración de stats como paso 1.

---

## CATEGORÍA 3: Diferencias en nombres de plugins (requieren mapeo)

| Nuestro plugin | iTOP equivalente | Contexto |
|---|---|---|
| `scormadvancedgrades` | `modulestats` | Columnas de stats de actividades |
| `coursecustomfield` | `usercoursefield` | Campos personalizados de curso |
| `userstatsadvanced` | `userstats` | Estadísticas de usuario |

**Impacto:** Solo afecta el campo `pluginname` dentro de `components.columns.elements[n]`. Cada plugin registra sus propias columnas con nombres diferentes pero la funcionalidad es equivalente. Nuestras columnas ya usan nuestros propios nombres de plugin.

**Decisión:** NO migrar — nuestro plugin necesita usar SUS propios nombres de plugin para funcionar. La equivalencia funcional ya está resuelta en el código del plugin.

---

## CATEGORÍA 4: Diferencias significativas que SÍ requieren evaluación

### 4a. Nombre de columna: "Apellido" vs "Apellidos"

- **Nuestro plugin:** `Apellido` (singular)
- **iTOP:** `Apellidos` (plural)

**Impacto:** Visible para el usuario en la cabecera de la columna.
**Acción recomendada:** Verificar qué campo de Moodle se usa (`lastname`). Si ambos muestran el mismo dato, es solo cosmético. Se podría ajustar el header en nuestra plantilla si el cliente lo requiere.

### 4b. Campo `summary` con sufijos extra

- **Nuestro plugin:** Agrega sufijo como ` (Puntuación tarea "Test assignment name")`
- **iTOP:** No tiene ese sufijo

**Impacto:** Cosmético — el summary aparece en el tooltip o descripción de la columna.
**Acción recomendada:** Bajo impacto. Se puede ajustar si el cliente lo nota.

### 4c. Formato de exportación (`export`)

- **Nuestro plugin:** `slk,xls,`
- **iTOP:** `pdf,xls,`

**Impacto:** Los formatos de exportación disponibles son diferentes. Nuestro plugin ofrece SLK en vez de PDF.
**Acción recomendada:** **MIGRAR** — actualizar el campo `export` en `block_configurable_reports` para que coincida con `pdf,xls,` de iTOP. Esto es un campo a nivel de reporte, no de componente.

### 4d. Campo `stat_type` vs `stat` para stats no-scormadvancedgrades

- **Nuestro plugin:** Usa `stat_type: "dias_conexion"` (para `userstatsadvanced`)
- **iTOP:** Usa `stat: "distinct_days_connection"` (para `userstats`)

**Impacto:** Estos son nombres de métricas diferentes entre plugins equivalentes. La funcionalidad debe ser la misma pero con nombres propios de cada plugin.
**Acción recomendada:** NO migrar — cada plugin usa su propio vocabulario interno de métricas. Si la métrica "dias_conexion" en `userstatsadvanced` produce el mismo resultado que "distinct_days_connection" en `userstats`, no hay diferencia funcional.

### 4e. Columnas ausentes / extra (desplazamiento de posiciones)

En algunos reportes como **007-Consumo de licencias SCORM**:
- Nuestro plugin tiene columnas que iTOP no tiene
- iTOP tiene columnas que nuestro plugin no tiene (ej: "Matrícula activa" con stat `active_enrolled`)
- Esto causa un desplazamiento de índices en las posiciones de las columnas

**Impacto:** **ALTO** — el usuario verá columnas diferentes.
**Acción recomendada:** Requiere análisis caso por caso:
  1. Identificar qué columnas faltan en nuestro plugin
  2. Verificar si el dato es proporcionado por una métrica equivalente en nuestro plugin
  3. Agregar columnas faltantes o eliminar extras según corresponda

### 4f. Sección `conditions` (condiciones del reporte)

- **Nuestro plugin:** Usa condición "Usuarios en el curso actual" (plugin distinto)
- **iTOP:** Usa condición "Usuarios con un rol determinado"

**Impacto:** **ALTO** — filtra DIFERENTES usuarios para el reporte. Una condición filtra por estar inscrito en el curso, la otra filtra por tener un rol específico.
**Acción recomendada:** **EVALUAR** cuidadosamente. En la mayoría de casos "usuarios en el curso actual" incluye a todos los inscritos, mientras que "usuarios con un rol determinado" podría filtrar solo estudiantes. Si iTOP filtra por rol "estudiante", el resultado podría ser más restrictivo. Hay que verificar qué condición produce el set de datos correcto.

### 4g. Sección `permissions` (permisos de acceso)

- **Nuestro plugin:** Algunas reports no tienen configuración de permisos
- **iTOP:** Sí tiene configuración de permisos en esos mismos reportes

**Impacto:** **MEDIO** — afecta quién puede ver cada reporte.
**Acción recomendada:** Migrar los permisos de iTOP a nuestro plugin para los reportes que los necesiten.

---

## CATEGORÍA 5: Reportes sin pareja

- **Solo en nuestro plugin:** `009-Informe de Trazabilidad` (no existe en iTOP)
- **Solo en iTOP:** `08 Informe general de tiempos (Alumnado)` — variante del reporte de tiempos que nuestro plugin no tiene

**Acción recomendada:** Verificar si el reporte de Trazabilidad es nuevo/adicional nuestro (OK) y si el de Tiempos (Alumnado) se necesita replicar.

---

## Plan de acción propuesto (por prioridad)

### Fase 1 — Ya lista para aplicar
1. **Migrar stats** (12 cambios en curso 601) — `migrar_stats_itop_cr.php` ✅

### Fase 2 — Migración de campo `export`
2. **Actualizar formato de exportación** — Crear script para cambiar `slk,xls,` → `pdf,xls,` en todos los reportes

### Fase 3 — Requiere análisis detallado
3. **Auditar columnas faltantes/extra** por reporte
4. **Evaluar condiciones** (`conditions`) — ¿cuál produce los datos correctos?
5. **Migrar permisos** (`permissions`) de iTOP

### Fase 4 — Cosmético (baja prioridad)
6. Ajustar "Apellido" → "Apellidos" si el cliente lo requiere
7. Limpiar sufijos de `summary` si molesta

---

## Próximos pasos inmediatos

Para poder avanzar con las Fases 2 y 3 necesito:

1. **Ejecutar la comparación** desde el servidor dev (yo no tengo acceso SSH directo). Comando:
   ```bash
   cd /home/ubuntu/moodle_phyton_intalacion_reporte_configurable
   git pull
   python3 comparar_componentes_cr.py
   ```
   Seleccionar adalid, modo remoto. El resultado se guarda en `comparacion_componentes_resultado.json`.

2. **Tu decisión** sobre qué nivel de "idéntico" necesitamos:
   - **Nivel A:** Solo stats + export (Fases 1-2) — lo funcional coincide
   - **Nivel B:** + columnas faltantes + condiciones + permisos (Fases 1-3) — configuración completa
   - **Nivel C:** + ajustes cosméticos (Fases 1-4) — pixel-perfect

¿Qué nivel quieres que apuntemos?
