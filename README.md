# Migración de reportes de curso: "Informes Avanzados" → "Configurable Reports" (por SSH)

Automatiza, por **SSH**, la migración de un plugin de reportes **alquilado**
(*Informes Avanzados*, `block_advanced_reports`, código cerrado) a un plugin
**propio** (*Configurable Reports*, `block_configurable_reports`) en una o
varias plataformas Moodle: detectar en qué cursos está activo el alquilado,
instalar el propio, agregar su bloque y sustituir los reportes curso por
curso, y (opcional) desinstalar el alquilado al terminar.

## Contexto: son dos plugins distintos

`block_advanced_reports` y `block_configurable_reports` **no comparten**
`component` ni tablas — son plugins independientes desde el punto de vista de
Moodle. Por eso esto NO es "actualizar" el alquilado: es instalar el propio
como un plugin nuevo y, curso por curso, sustituir uno por otro.

Como el alquilado es de código cerrado/ofuscado, estas herramientas nunca
tocan ni leen sus tablas internas: solo se apoyan en una señal 100% pública
de Moodle, la tabla `block_instances` (qué bloque está añadido a qué
página), para saber en qué cursos está activo.

El flujo completo tiene 4 pasos, cada uno con su propio script:

1. **`auditar_cr.py`** (solo lectura) — recorre cada plataforma y detecta en
   qué cursos está añadido el bloque alquilado (`advanced_reports`) — esos son
   los que hay que migrar — y en cuáles ya está el propio
   (`configurable_reports`) con sus reportes, para confirmar avance. Guarda el
   resultado en `auditoria_resultado.json`.
2. **`desplegar_plugin_cr.py`** — instala el código del plugin propio en
   `blocks/configurable_reports` (instalación nueva, no toca `advanced_reports`
   en absoluto) y corre `purge_caches.php` + `upgrade.php` para que Moodle lo
   registre.
3. **`instalar_plantillas_cr.py`** (modo migración) — para cada curso
   detectado en el paso 1: agrega la instancia del bloque propio a la página
   del curso si falta, borra cualquier reporte propio de una corrida previa, y
   crea las 8 plantillas. No toca el plugin alquilado.
4. **`desinstalar_plugin_alquilado_cr.py`** (opcional, a nivel de
   **plataforma completa**, no por curso) — una vez migrados todos los cursos
   de esa plataforma, borra el código y las tablas de `block_advanced_reports`
   con `admin/cli/uninstall_plugins.php`. Hace backup antes de tocar nada.

> Los pasos 2 y 3 son independientes entre sí y del paso 4: puedes auditar y
> decidir sin instalar nada, o instalar el plugin propio en cursos nuevos sin
> tocar el alquilado en otros.

## Cómo funciona cada script

Todos comparten `cr_common.py` (conexión SSH/SFTP con `paramiko`, lectura de
`inventario.json`, ejecución remota con soporte de `sudo`, empaquetado y
descarga de backups).

**`auditar_cr.py`**: sube `audit_cr.php` a `/tmp` y lo ejecuta como usuario
web (de solo lectura). Reporta, por plataforma, si cada plugin está presente
en disco y su versión en BD; por curso, si el bloque alquilado y/o el propio
están añadidos a la página, y los reportes propios existentes (nombre/tipo).
Del alquilado **nunca** consulta tablas propias suyas — solo `block_instances`
y `config_plugins` (ambas son de Moodle core, no del plugin).

**`desplegar_plugin_cr.py`**: si por algún motivo ya había algo en
`blocks/configurable_reports` (p.ej. una migración previa) lo empaqueta
(`tar.gz`) y lo descarga a `backups/` antes de tocar nada; luego sube el
código de la carpeta que indiques (la raíz del plugin, la que contiene
`version.php`) a una carpeta temporal, lo mueve a `blocks/configurable_reports`,
ajusta el dueño (`web_user:web_group`) y ejecuta `purge_caches.php` +
`upgrade.php --non-interactive` como usuario web.

**`instalar_plantillas_cr.py`**: crea una carpeta temporal en `/tmp`, sube los
`.xml` de `plantillas/` y el CLI PHP `import_cr_templates.php`, y lo ejecuta
como usuario web. El CLI arranca Moodle, reutiliza la lógica nativa del plugin
propio (`xmlize` + `cr_unserialize` + `cr_serialize`) e inserta cada reporte en
`block_configurable_reports`. Borra siempre los temporales (`finally`). Tiene
dos modos:

- **Instalación normal**: idempotente por nombre — si el curso ya tiene un
  reporte con el mismo nombre, se **omite** (o se **sobrescribe** con
  "Actualizar existentes" / `"force": true`).
- **Migración**: toma los cursos de `auditoria_resultado.json` (paso 1) y usa
  `--addblock` (agrega el bloque propio si falta) + `--wipe` (borra reportes
  **propios** existentes en ese curso antes de crear los 8 nuevos, por si se
  re-ejecuta). Nunca toca el plugin alquilado.

**`desinstalar_plugin_alquilado_cr.py`**: revisa `auditoria_resultado.json`
y avisa si alguna plataforma seleccionada todavía tiene cursos con el
alquilado activo; pide confirmación extra para continuar igual. Hace backup
de `blocks/advanced_reports`, corre `admin/cli/uninstall_plugins.php
--plugins=block_advanced_reports --run`, borra la carpeta, y purga cachés.
Pide escribir `DESINSTALAR` literalmente para confirmar por ser irreversible
(salvo el backup).

## Requisitos

```bash
python3 -m venv .venv
# Windows:  .venv\Scripts\activate
# Linux:    source .venv/bin/activate
pip install -r requirements.txt
```

- Acceso SSH a cada servidor con un usuario que pueda hacer `sudo` (tanto
  `sudo -u <web_user>` como `sudo` a secas, sin contraseña o configurándola en
  el inventario). `desplegar_plugin_cr.py` y `desinstalar_plugin_alquilado_cr.py`
  necesitan además permiso para `tar`, `mv`, `rm -rf` y `chown` dentro de
  `blocks/`.
- Para `auditar_cr.py`: no requiere que ningún plugin propio esté instalado
  todavía; detecta lo que haya.
- Para `desplegar_plugin_cr.py`: la carpeta local con el código del plugin
  propio (la que contiene `version.php`).
- Para `instalar_plantillas_cr.py` en modo migración: haber corrido antes
  `auditar_cr.py` y `desplegar_plugin_cr.py`.

## Configuración — `inventario.json`

Acepta un array de servidores **o** un objeto `{ "settings": {...}, "servers": [...] }`.

Campos por servidor (los 5 primeros son obligatorios):

| Campo                    | Descripción                                             |
|--------------------------|---------------------------------------------------------|
| `name`                   | Nombre visible de la plataforma                         |
| `host`                   | IP o dominio SSH                                         |
| `ssh_user`               | Usuario SSH                                              |
| `moodle_path`            | Ruta absoluta del Moodle (contiene `config.php`)        |
| `web_user`               | Usuario del servidor web (p.ej. `www-data`, `apache`)   |
| `port`                   | Puerto SSH (def. 22)                                    |
| `ssh_password`           | Contraseña SSH (o usa `ssh_key_path`)                   |
| `ssh_key_path`           | Ruta a la llave privada SSH                             |
| `ssh_key_passphrase`     | Passphrase de la llave (si aplica)                      |
| `web_group`              | Grupo web (def. = `web_user`)                           |
| `sudo_requires_password` | `true` si `sudo` pide contraseña                        |
| `sudo_password`          | Contraseña de `sudo` (si lo anterior es `true`)         |
| `report_courseids`       | **Cursos destino**: lista de ids y/o shortnames (modo instalación normal) |

`settings` (opcional, valores por defecto globales):

| Clave                | Descripción                                                            |
|----------------------|------------------------------------------------------------------------|
| `templates_dir`      | Carpeta local con los `.xml` (def. `./plantillas`)                     |
| `force`              | `true` = actualizar existentes; si se omite, se pregunta               |
| `owner`              | `ownerid` del reporte en Moodle (def. = administrador principal)       |
| `report_courseids`   | Cursos por defecto si un servidor no define los suyos (modo normal)    |
| `plugin_source_dir`  | Carpeta local con el código del plugin propio (para `desplegar_plugin_cr.py`) |

> Los `courseids` pueden ser **ids numéricos** o **shortnames** de curso. Como
> los ids difieren entre plataformas, lo más robusto es definirlos por servidor.
> Si un servidor no los trae, el script los pedirá de forma interactiva.

## Uso

### Paso 1 — Auditar

```bash
python3 auditar_cr.py
```

Selecciona las plataformas a revisar. Muestra, por curso: si el bloque
alquilado está añadido (columna "¿Migrar?"), si el propio ya está y cuántos
reportes tiene. Genera `auditoria_resultado.json`.

### Paso 2 — Instalar el plugin propio

```bash
python3 desplegar_plugin_cr.py
```

Indica la carpeta del plugin propio (la que contiene `version.php`), elige
las plataformas y confirma.

### Paso 3 — Agregar bloque + sustituir reportes

```bash
python3 instalar_plantillas_cr.py
```

Elige el modo **Migración**: usa automáticamente los cursos de
`auditoria_resultado.json`, agrega el bloque propio donde falte, borra
reportes propios de corridas previas y crea las 8 plantillas. Pide
confirmación explícita por ser masivo. (El modo **Instalación normal** sigue
disponible para instalar plantillas a mano en cursos que indiques, sin
depender de la auditoría.)

### Paso 4 — Desinstalar el alquilado (opcional)

```bash
python3 desinstalar_plugin_alquilado_cr.py
```

Solo cuando ya confirmaste (re-corriendo `auditar_cr.py`) que ninguna
plataforma tiene cursos pendientes. Pide escribir `DESINSTALAR` para
confirmar.

## Plantillas incluidas (`plantillas/`)

1. Informe Global · 2. Progreso · 3. Mensajes · 4. Registros ·
5. Detalle Evaluaciones · 6. Profesores · 7. Consumo de licencias SCORM ·
8. Informe general de tiempos

Para añadir o cambiar plantillas, exporta el reporte desde Moodle
(*Configurable Reports → Exportar*) y deja el `.xml` en `plantillas/`.

## Notas y límites

- La auditoría **nunca** lee ni modifica tablas propias del plugin alquilado
  (código cerrado): solo usa `block_instances` (qué bloque está añadido a qué
  página) y `config_plugins` (versión registrada), que son tablas de Moodle
  core, iguales para cualquier plugin.
- `needs_migration` = el bloque alquilado está añadido a ese curso. Es la
  señal que usan tanto `auditar_cr.py` para reportar como
  `instalar_plantillas_cr.py` (modo migración) para decidir qué cursos tocar.
- El bloque propio se agrega en la región `side-pre` con
  `pagetypepattern = course-view-*` (el patrón estándar de "agregar bloque"
  desde la interfaz de Moodle). Si tu tema usa otra región principal, muévelo
  manualmente una vez desde la página del curso.
- La idempotencia en modo instalación normal es por **nombre exacto** del
  reporte dentro del curso. En modo migración (`--wipe`) no aplica: se borran
  todos los reportes **propios** del curso sin comparar nombres (nunca se
  tocan los del alquilado, que viven en otra tabla).
- Los CLIs PHP escriben directamente vía `$DB` (omiten chequeos de
  capacidad), por eso se ejecutan como `web_user`: son tareas administrativas
  de servidor.
- Reportes de tipo SQL: se ajusta automáticamente el `courseid` y se
  normalizan comillas en la consulta, igual que el importador nativo de
  Moodle.
- Los backups (`desplegar_plugin_cr.py` y `desinstalar_plugin_alquilado_cr.py`)
  quedan en `backups/<servidor>/` (no se suben a git salvo que lo decidas);
  consérvalos hasta confirmar que la migración fue exitosa.
- `admin/cli/uninstall_plugins.php` borra las tablas y el registro del plugin
  en Moodle, pero no puede deshacerse salvo restaurando el backup (código) y
  una copia de la base de datos previa (para los datos) — este proyecto no
  hace backup de base de datos completa, solo del código del plugin.
