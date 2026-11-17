# feat: Ponentes y actividades del evento, y paso de una propuesta aceptada al programa

> Plan 021. Sustituye al plan 018 (conferencistas invitados como propuestas aceptadas). Referencia de comportamiento: Eventool.

- **Fecha:** 2026-11-17
- **Estado:** Aprobado (2026-11-17: especificacion y DA-1 a DA-17 aprobadas por el usuario)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Un evento tiene tres cosas que hoy se mezclan: **convocatorias** (reciben propuestas), **ponentes** (las personas) y **actividades** (lo que ocurre en el programa). Este plan las separa:

- **Propuestas:** los datos que envía una persona a una convocatoria. Se evalúan y se aceptan o no.
- **Ponentes:** ficha de cada persona del evento, con sus datos personales.
- **Actividades:** cada charla, panel, taller o evento social del programa.

Al **aceptar** una propuesta, sus datos pasan solos a Ponentes (uno por representante) y a Actividades (una por propuesta). Ponentes y actividades también se dan de alta a mano, sin convocatoria, y se enlazan entre sí en cualquier momento.

**Dentro:**
- Modelos `Speaker`, `EventActivity`, sus categorías (catálogo propio por evento y distinto para cada uno) y la relación muchos a muchos entre ponentes y actividades.
- Panel (Filament) con las vistas **Ponentes** y **Actividades** dentro del evento (plan 020), con alta, edición, publicación y eliminación.
- Acción que pasa una propuesta aceptada a ponentes y actividad, sin duplicar personas y sin pisar datos ya editados; y su reversa (ocultar sin borrar).
- Nombre y apellido por separado en el bloque de participantes de la propuesta.
- Vista **Propuestas** sin las aprobadas por defecto (con filtro para verlas).
- Disco de archivos propio para fotografías e imágenes, listo para cambiar a GCP.
- Retirar los restos del plan 018 que ya no se usan.

**Fuera:**
- Sitio público con programa y ponentes (E-19) y agenda con arrastrar y soltar y choques (E-20): este plan solo deja la marca `is_published` y las fechas.
- Portal del ponente: el ponente no edita su ficha todavía.
- Decisiones del comité (plan 012): este plan le entrega la Action que debe llamar al aceptar y revertir; las transiciones siguen siendo del plan 012.
- Importación masiva de ponentes y actividades.
- Iconos en SVG (DA-11).

## 2. Punto de partida

Medido el 2026-11-17 sobre `staging` (`80ce2bb`):

- No existe ningún modelo ni vista de ponentes ni de actividades. El glosario lista `Speaker` (ligado a `Contact` de la organización) y `EventSession`, pero no están construidos. `App\Models\Activity` es la bitácora de auditoría (spatie), no el programa.
- `Contact` (organización): `full_name`, `email`, `country`. Lo crea el envío de propuestas.
- `SubmissionParticipant`: copia congelada al enviar, con `full_name` único, `email`, `country`, `job_title`, `company` y `bio`; ligada a un `Contact`.
- `Submission` tiene `track_id`, estados con `accepted`, y la columna `origin` (`call|invited`) con `CHECK`, pensada para el plan 018.
- El plan 012 (decisiones) está en `Borrador`: hoy nada cambia una propuesta a `accepted`, y aceptar no crea nada.
- Del plan 018 hay en `staging` solo la fase F0: `ResolveInvitationForm`, `SuggestInvitationForm`, `InvitationTarget`, el permiso `submissions.invite` y el valor `invited` de `SubmissionOrigin`. Las fases F1 a F4 viven solo en la rama `feat/plan-018-invitados` (no mergeada).

## 3. Decisiones

Todas las marcadas «Cerrada» las respondió el usuario el 2026-11-17 (rondas de preguntas de esta sesión); las demás son recomendaciones de este plan, pendientes de aprobar junto con el plan.

### DA-1 · Dueño del ponente — Cerrada: ficha por evento con vínculo opcional
`speakers` pertenece a un evento. Lleva `contact_id` opcional (la persona de la organización de la que viene) para sugerir y copiar datos de eventos anteriores. Editar la ficha de un evento no cambia la de otro.

### DA-2 · Momento del paso — Cerrada: al aceptar, automático
La Action `PromoteSubmissionToProgram` corre en la misma transacción que la aceptación. Ponentes y actividad nacen **publicados** (`is_published = true`, DA-6).

### DA-3 · Reversa — Cerrada: se ocultan, no se borran
Revertir (`accepted → under_review`) o retirar una propuesta aceptada pone `is_published = false` en la actividad y en los ponentes que **solo** participan en esa actividad (un ponente que está en otra actividad publicada sigue visible), y marca `hidden_by_reversal_at`. Se avisa en pantalla a quien revierte (cuántos ponentes y qué actividad), queda en auditoría y los registros muestran la insignia «Oculto por reversa». Sin correo. Un organizador puede republicar o eliminar. Si la propuesta se acepta otra vez, la actividad y los ponentes se reutilizan y se republican solo los marcados por reversa.

### DA-4 · Actividad sin fecha — Cerrada: fecha y hora opcionales («sin programar»)
Desde una propuesta la actividad nace sin fecha ni hora. A mano, en el formulario, fecha de inicio, fecha de fin, hora de inicio y hora de fin son obligatorias. Un `CHECK` exige las cuatro juntas o ninguna, y fin no anterior al inicio.

### DA-5 · Duplicados — Cerrada: reutilizar y completar solo campos vacíos
Si un participante aprobado ya es ponente del evento (mismo correo, sin distinguir mayúsculas), no se crea otro: se enlaza a la actividad y se rellenan solo los campos que estén vacíos. Lo editado a mano nunca se pisa. Un índice único `(event_id, lower(email))` lo garantiza.

### DA-6 · Publicación — Cerrada: marca `is_published`, encendida al crear
Cada ponente y cada actividad tiene `is_published`. El sitio público (futuro) solo muestra lo publicado.

### DA-7 · Categorías — Cerrada: catálogos independientes, varias por registro
`speaker_categories` y `activity_categories`, propias de cada evento, sin vínculo con los ejes (`Track`) ni entre sí. Un ponente o una actividad admite varias. Al pasar desde una propuesta la categoría queda vacía.

### DA-8 · Relación ponente–actividad — Cerrada: muchos a muchos
Tabla `activity_speaker`. Cada lado existe sin el otro. Se asigna desde la actividad y desde la ficha del ponente. **Invitados (sustituye al plan 018):** se dan de alta directo en Ponentes; la actividad es opcional y se elige después entre las existentes.

### DA-9 · Datos del ponente — Cerrada: pedir lo básico en la propuesta
La propuesta pide nombre, apellido, correo, país, organización, cargo y biografía. Redes, descripción de una línea, fotografía y categoría las completa el organizador. Obligatorios en el ponente: **nombre y apellido**. El formulario de la convocatoria pasa a pedir nombre y apellido por separado (ambos obligatorios, cambio en plan 006).

### DA-10 · Propuestas ya aprobadas — Cerrada: salen de la lista por defecto
La vista Propuestas muestra por defecto las no aprobadas (sin `accepted`), y las aprobadas se ven con el filtro de estado. La actividad enlaza a su propuesta de origen para conservar la trazabilidad hacia la evaluación.

### DA-11 · Imágenes e icono — Cerrada: disco propio preparado para GCP
Un disco `media` en `config/filesystems.php`, local hoy y cambiable a Google Cloud Storage con variables de entorno (`MEDIA_DISK`, sin tocar el código). Guarda la fotografía del ponente, la imagen de la actividad y el icono (se sube como archivo). Tipos: JPEG, PNG y WebP; fotografía e imagen hasta 5 MB, icono hasta 256 KB. **SVG queda fuera** hasta aprobar una dependencia de saneado (acción 4 de `AGENTS.md`). Nombres generados; la ruta se guarda, no la URL.
**Pendiente de confirmar:** si el disco es público o privado. Recomendación: privado, servido por una ruta de la aplicación que comprueba `is_published`, con un `Cache-Control` público para registros publicados; así una imagen despublicada deja de ser accesible. Cambiarlo a público solo exige un ADR que exceptúe la regla de archivos privados.

### DA-12 · Hora de la actividad — Cerrada: hora local del evento
`starts_on` y `ends_on` (`date`), `starts_at` y `ends_at` (`time`), sin zona; se interpretan en la zona horaria del evento. Es una excepción a «fechas en UTC», justificada porque un programa se lee en la hora del lugar. Se anota en `docs/database-design.md`.

### DA-13 · Permisos — Cerrada: administrador y organizador gestionan
Permisos nuevos `speakers.manage` y `activities.manage` para `org_admin` y `organizer`; eliminar es solo del `org_admin` (`speakers.delete`, `activities.delete`). El `reviewer` no ve nada. Cada Action y Policy comprueba el permiso y la organización.

### DA-14 · Nombres en el código — recomendada
Ponente: `Speaker` (el glosario ya lo define; se corrige su ámbito a **Evento**). Actividad: `EventActivity`, tabla `event_activities`, **sustituyendo a `EventSession`** en el glosario, porque `Activity` ya es la bitácora. En el panel se llaman «Ponentes» y «Actividades».

### DA-15 · Restos del plan 018 — recomendada: dejarlos inactivos por ahora
La rama `feat/plan-018-invitados` se descarta sin mergear. En `staging` quedan `ResolveInvitationForm`, `SuggestInvitationForm`, `InvitationTarget`, el permiso `submissions.invite` y la columna `origin` con su `CHECK` (la migración de `submissions` ya está aplicada en bases reales; quitarla es un cambio de datos que pide propuesta aprobada). Este plan **no los usa**. La fase F0 retira el código y el permiso sin uso y deja un punto en `docs/sprints/deuda-tecnica.md` para decidir sobre la columna `origin`.

### DA-16 · Texto enriquecido — recomendada
Biografía y descripción amplia se editan con el editor enriquecido de Filament (`RichEditor`), se guardan como HTML y se sanean al guardar y al mostrar (lista blanca: párrafos, negritas, cursivas, listas, enlaces `https` con `rel="noopener noreferrer nofollow"`). Sin imágenes embebidas.

### DA-17 · Redes sociales — recomendada
Columna `social_links` (`jsonb`), lista de `{network, url}` con red única por ponente. Redes: LinkedIn, X, Instagram, Facebook, YouTube, TikTok y Sitio web. Cada URL, `https` y con el dominio de su red (el sitio web, cualquier dominio). Un Form Request lo valida; el `CHECK` de la base exige que sea un arreglo.

## 4. Contrato

Todo viaja con la propuesta de `docs/database-design.md` §6 (a escribir en F0 y aprobar con el plan). Sin rutas JSON ni webhooks nuevos. Todas las tablas tienen `organization_id` y `event_id` con claves foráneas compuestas, igual que `submissions`.

- **`speakers`:** `id uuid`, `organization_id`, `event_id`, `contact_id` nulo, `first_name varchar(100)`, `last_name varchar(100)`, `email varchar(254)` nulo, `country` nulo, `company varchar(160)` nulo, `job_title varchar(160)` nulo, `tagline varchar(160)` nulo (descripción de una línea), `bio text` nulo (HTML saneado), `photo_path` nulo, `social_links jsonb default '[]'`, `is_published bool default true`, `hidden_by_reversal_at timestamptz` nulo, `created_by_submission_id` nulo, `deleted_at`, `timestamps`. Único `(event_id, lower(email))` donde el correo no es nulo y `deleted_at` es nulo.
- **`event_activities`:** `id uuid`, `organization_id`, `event_id`, `submission_id` nulo y único (una actividad por propuesta), `title varchar(200)`, `starts_on`, `ends_on`, `starts_at`, `ends_at` (nulos juntos, DA-4 y DA-12), `icon_path` nulo, `image_path` nulo, `summary varchar(200)` nulo (descripción de una línea), `description text` nulo (HTML saneado), `is_published bool default true`, `hidden_by_reversal_at` nulo, `deleted_at`, `timestamps`.
- **`speaker_categories`, `activity_categories`:** `id`, `organization_id`, `event_id`, `name varchar(100)`, `position`, único `(event_id, lower(name))`. Tablas pivote `speaker_speaker_category` y `activity_activity_category`.
- **`activity_speaker`:** `activity_id`, `speaker_id`, `position`, clave primaria compuesta, ambos del mismo evento (clave foránea compuesta).
- **`submission_participants`:** se agregan `first_name` y `last_name` (`NOT NULL` para los nuevos); `full_name` se conserva como el texto «nombre apellido» para las ya enviadas. Las anteriores se parten al aprobar con «primera palabra, resto» y el organizador corrige.
- **Actions** (`app/Actions/Program/`): `CreateSpeaker`, `UpdateSpeaker`, `DeleteSpeaker`, `CreateActivity`, `UpdateActivity`, `DeleteActivity`, `SyncActivitySpeakers`, `PromoteSubmissionToProgram`, `HideProgramOfSubmission`, `StoreProgramImage`. Cada una recibe un array validado y tiene su Form Request.
- **Plan 012:** su Action de aceptar llama a `PromoteSubmissionToProgram` y la de revertir o retirar a `HideProgramOfSubmission`. Hasta que exista, las pruebas las invocan directo.
- **Permisos y Policies:** `SpeakerPolicy` y `EventActivityPolicy`; categorías cubiertas por los mismos permisos.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` y `organizer`: ver, crear, editar, publicar y asignar. `org_admin`: eliminar. `reviewer` y autores: nada (DA-13) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SpeakerPolicy` y `EventActivityPolicy`; `tests/Feature/Program/*PolicyTest.php` |
| ¿Modelos nuevos con datos de una organización? | Sí, todos: `Speaker`, `EventActivity`, las dos categorías. Usan `BelongsToOrganization`; la prueba de aislamiento del núcleo los recorre |
| ¿El acceso cruza eventos? | No. Todo se acota por evento; un ponente de otro evento nunca se ofrece en el selector (CA-021-9) |
| ¿Datos personales nuevos? | Sí: correo, país, organización, cargo, biografía, fotografía y redes de ponentes. Base legal: consentimiento expreso para la publicación (`docs/privacidad.md` ya lo prevé). Se publica solo con `is_published`; borrado lógico y eliminación definitiva a pedido. Se actualiza `docs/privacidad.md` |
| ¿Se suben archivos? | Sí: JPEG, PNG y WebP; fotografía e imagen hasta 5 MB y icono hasta 256 KB; disco `media`, nombre generado, validación por contenido (DA-11) |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos? | No (DA-3: solo aviso en pantalla) |
| ¿Qué se audita? | Alta, edición, publicación, eliminación, paso desde propuesta (con ids, sin datos personales) y reversa |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su commit. Orden fijo.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Glosario (Speaker por evento, `EventActivity`), propuesta de datos en `docs/database-design.md` §6, `privacidad.md`, plan 018 marcado como reemplazado, retiro del código del 018 sin uso en `staging` y de `submissions.invite`, punto de deuda de la columna `origin` | S |
| F1 | Disco `media`, `StoreProgramImage` y su prueba; catálogos de categorías (modelos, migración, CRUD en el panel) | M |
| F2 | Ponentes: migración, modelo, Policy, Actions, Form Requests, vista y formulario del panel (texto enriquecido, foto, redes, categorías) | L |
| F3 | Actividades: migración, modelo, Policy, Actions, vista y formulario del panel, asignación de ponentes, «sin programar» | L |
| F4 | Nombre y apellido por separado en el bloque de participantes (formulario, `SubmitSubmission`, migración de `submission_participants`) | M |
| F5 | `PromoteSubmissionToProgram` y `HideProgramOfSubmission` con duplicados, idempotencia y reversa; insignia y aviso | L |
| F6 | Vista Propuestas sin aprobadas por defecto y con filtro; enlace entre propuesta y actividad; menú del evento (plan 020) | M |
| F7 | Verificación en navegador a ~375 y ~1280 px, `composer check`, `docs/api` (sin cambios) y cierre | S |

## 7. Criterios de aceptación

- **CA-021-1:** *Dado* un organizador con `speakers.manage`, *cuando* crea un ponente con nombre y apellido, *entonces* queda en su evento, publicado, y el nombre y el apellido son los únicos obligatorios; sin apellido o sin nombre falla la validación.
- **CA-021-2:** *Dado* un ponente, *cuando* se guardan biografía enriquecida, descripción de una línea, organización, cargo, fotografía y redes, *entonces* se conservan, la biografía se guarda saneada (sin `script` ni atributos de evento) y una red repetida o con URL que no sea `https` o de otro dominio se rechaza.
- **CA-021-3:** *Dado* un ponente con una fotografía de tipo no permitido, falsa por contenido o mayor de 5 MB, *cuando* se sube, *entonces* se rechaza con error de validación y no se guarda ningún archivo.
- **CA-021-4:** *Dado* un organizador con `activities.manage`, *cuando* crea una actividad a mano, *entonces* el nombre, la fecha de inicio, la fecha de fin, la hora de inicio y la hora de fin son obligatorios, el fin no es anterior al inicio y el icono, la imagen, las descripciones, las categorías y los ponentes son opcionales.
- **CA-021-5:** *Dado* una actividad, *cuando* se le asignan ponentes existentes del evento o ninguno, *entonces* se guarda en ambos casos; un ponente puede estar en varias actividades y una actividad tener varios ponentes.
- **CA-021-6:** *Dado* un evento, *cuando* se crean categorías de ponentes y de actividades, *entonces* son independientes entre sí y entre eventos, un nombre repetido en el mismo evento se rechaza, y un ponente o una actividad admite varias.
- **CA-021-7:** *Dado* una propuesta `under_review` con dos participantes, *cuando* se acepta, *entonces* se crean dos ponentes individuales con sus datos y una actividad enlazada a la propuesta y a ambos ponentes, publicados, sin fecha ni hora y sin categoría.
- **CA-021-8:** *Dado* una propuesta aceptada, *cuando* se ejecuta el paso por segunda vez, *entonces* no se crea ningún ponente ni actividad adicional (idempotente).
- **CA-021-9:** *Dado* un participante cuyo correo ya es ponente del evento (sin distinguir mayúsculas), *cuando* se acepta su propuesta, *entonces* se reutiliza esa ficha, se rellenan solo los campos vacíos sin pisar los editados, y se enlaza a la actividad nueva. Un ponente de otro evento u organización nunca se reutiliza ni se ofrece en el selector.
- **CA-021-10:** *Dado* una propuesta aceptada con ponentes y actividad creados, *cuando* se revierte o se retira, *entonces* la actividad y los ponentes que solo participan en ella pasan a no publicados con la marca de reversa, nada se borra, quien revierte ve el aviso con los conteos, y se audita.
- **CA-021-11:** *Dado* un ponente oculto por reversa que también está en otra actividad publicada, *cuando* se revierte la primera propuesta, *entonces* ese ponente sigue publicado.
- **CA-021-12:** *Dado* una propuesta revertida y aceptada otra vez, *cuando* se vuelve a aceptar, *entonces* se reutilizan los registros ocultos por reversa y se republican, sin duplicarlos ni pisar lo editado.
- **CA-021-13:** *Dado* el formulario de la convocatoria, *cuando* se envía una propuesta con un participante, *entonces* nombre y apellido se piden por separado, ambos obligatorios, y se guardan separados; las propuestas anteriores con `full_name` se parten al aprobar.
- **CA-021-14:** *Dado* la vista Propuestas, *cuando* se abre, *entonces* no muestra las aceptadas por defecto, las muestra con el filtro de estado, y la actividad enlaza a su propuesta.
- **CA-021-15:** *Dado* un usuario `reviewer` o de otra organización, *cuando* intenta ver o cambiar ponentes y actividades, *entonces* se deniega; eliminar exige `org_admin`; todo en el servidor.
- **CA-021-16:** *Dado* un ponente o una actividad borrados, *cuando* se consultan las listas, *entonces* no aparecen, y el borrado de una actividad no borra a sus ponentes ni al revés.
- **CA-021-17:** *Dado* dos organizaciones, *cuando* una consulta o edita por identificador un ponente o una actividad de la otra, *entonces* recibe 404 y no se filtra ningún dato (prueba de aislamiento).
- **CA-021-18:** *Dado* el cambio de `MEDIA_DISK`, *cuando* se sube una imagen, *entonces* se guarda en el disco configurado sin tocar el código, y una imagen de un registro no publicado no se sirve al público.
- **CA-021-19:** *Dado* el panel, *cuando* se abren Ponentes y Actividades a ~375 y ~1280 px con la consola abierta, *entonces* se ven sin errores, con los estados de carga, vacío, error y éxito.
  Manual: abrir el evento, entrar a Ponentes y a Actividades, crear uno de cada uno y asignarlos; a ~375 y ~1280 px; consola sin errores.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El plan 012 aún no existe: nadie llama a `PromoteSubmissionToProgram` | Se entrega la Action probada y 012 la llama; hasta entonces el paso se prueba directo y se registra en el plan 012 |
| Se pierden datos del plan 018 | Se aprueba su descarte (usuario, 2026-11-17); la rama sigue en GitHub hasta que el usuario confirme borrarla |
| Cambio de `submission_participants` toca el plan 006 de Gabriel | F4 es una fase aparte, con migración propuesta y aprobada; se avisa a Gabriel |
| HTML enriquecido: XSS | Saneado al guardar y al mostrar, con pruebas de cargas maliciosas (CA-021-2) |
| Hora local sin zona se interpreta mal | Siempre se muestra con la zona del evento; documentado en `database-design.md` |
| Correo duplicado con distinta capitalización o alias | Índice único sobre `lower(email)`; los alias `+` no se normalizan (decisión explícita) |
| Datos personales de ponentes | `privacidad.md` actualizada; publicación solo con `is_published` |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | `a60e81d` | 2026-11-17 | Retiro del código del 018 sin uso (`ResolveInvitationForm`, `SuggestInvitationForm`, `InvitationTarget`, `InvitationFormNotPublished`, `InvitationFormTest`, permiso `submissions.invite`, cadenas `invitation_*`) y documentación: glosario, propuesta de datos en `docs/database-design.md` §8.1, `privacidad.md`, plan 018 «Reemplazado por el plan 021» (la guardiana solo revisa «En curso» y «Terminado»), plan 012 y plan 001 ajustados, deuda DT-17. Solo documentación y retiro de código: sin pruebas nuevas. `tests/Feature/Auth`, `Submissions` y `tests/Arch`: 82 fallos antes y 82 después, la misma lista (419 CSRF del entorno, medido con `git stash`); `LangParity|ModelsAreScoped|TenantIsolation|RolesMatrix`: 151 pasan; PHPStan «No errors»; Pint «PASS»; `check-criteria.php`: 0 errores, 12 avisos previos del plan 002; `git diff --check` limpio |
| F1 | `a3443f9` | 2026-11-17 | Commits: `a3443f9` disco `media` (local y privado, `MEDIA_DISK`, helper `MediaDisk`) y `StoreProgramImage` / `DeleteProgramImage`; `8353075` migración, modelos y factories de `speaker_categories` y `activity_categories`; `d78286a` permisos `speakers.*` y `activities.*` (matriz, prueba de matriz, plan 001, migración de datos que resiembra roles) y Policies; `637083e` Actions y Form Requests de categorías; `9eb30a0` páginas del panel en el submenú del evento. Cobertura parcial en esta fase: CA-021-3 solo la validación y el guardado de la imagen (se cablea al ponente en F2 y a la actividad en F3); CA-021-6 todo salvo «varias categorías por ponente o actividad» (pivotes en F2 y F3); CA-021-15 y CA-021-17 solo para categorías (ponentes y actividades en F2 y F3); CA-021-18 solo el disco configurable («no se sirve si no está publicado» es de F2 y F3). Las migraciones solo se escribieron: las pruebas las aplican a `testing_021`. Validaciones: pruebas nuevas 62 pasan en `tests/Feature/Program`; lote `Arch`, `Panel`, `Events`, `Tenancy`, `Program`, `RolesMatrix`, `LangParity`, `Calls`, `Submissions`: 808 pasan y 2 fallaban (prueba del submenú del plan 020 que esperaba tres entradas; actualizada y en verde). Las pruebas de Filament con datos de acciones corren con `APP_ENV=testing` (en este contenedor `APP_ENV=local` anula el de `phpunit.xml` y `fillForm` no hace nada: limitación del entorno, no del código). PHPStan «No errors»; Pint «PASS»; `check-criteria.php`: 0 errores, 12 avisos previos del plan 002; `git diff --check` limpio |
