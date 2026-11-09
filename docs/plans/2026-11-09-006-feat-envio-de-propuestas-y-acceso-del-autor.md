# feat: envío de propuestas y acceso del autor

> Plan de la épica E-06 (R0). Es la especificación de SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. Fuente: `docs/plan-de-desarrollo.md` §10.1. Es una de las superficies públicas más expuestas del producto: la seguridad no se recorta.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Que una persona sin cuenta envíe una propuesta desde la página pública con el formulario que definió el organizador, y que después pueda consultarla, corregirla mientras la convocatoria esté abierta y retirarla, usando un enlace de acceso de un solo uso. Cada envío registra el consentimiento con la versión del aviso de privacidad del evento y conserva una instantánea de la definición del formulario con la que se envió.

**Dentro:** modelo `Submission` con bloque fijo (título, eje y participantes) más respuestas dinámicas; participantes con mínimo y máximo por convocatoria (RC-9); formulario público con `FormRenderer`; idempotencia ante doble envío emitida por el servidor; límite de peticiones y anti-robots (Turnstile); token de carga para las subidas del plan 007; acuse y enlace de acceso; edición y retiro por el autor («Debería»); consentimiento registrado y declaración de autorización de terceros; instantánea de la definición; implementación real de `TrackUsageChecker` y `SubmissionAnswerCounter`; auditoría.

**Fuera:** subida y almacenamiento de archivos (plan 007; aquí solo se referencian cargas ya hechas), plantillas y envío de correos (plan 008), panel del organizador (plan 009), decisiones y estados posteriores a `submitted` y `withdrawn` (plan 012), borradores (RC-8), cuentas para autores (R2), SSR (RC-5) y aviso por correo a los participantes secundarios (DA-8).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-006-1 | Como autor, quiero enviar mi propuesta con el formulario del evento, mis datos y los de los participantes, para participar (backend de `SubmitSubmission`) | M | H-005-3, H-005-4, H-007-1 |
| H-006-2 | Como organizador, quiero límite de peticiones y anti-robots en los formularios públicos y en las cargas, para evitar spam y abuso | S | H-006-1 |
| H-006-3 | Como autor, quiero recibir un acuse y pedir un enlace de acceso de un solo uso, para consultar mi propuesta | M | H-006-1, H-008-6 |
| H-006-4 | Como autor, quiero editar mi propuesta mientras la convocatoria esté abierta, para corregir errores («Debería») | M | H-006-3 |
| H-006-5 | Como autor, quiero retirar mi propuesta, para no participar («Debería») | S | H-006-3 |
| H-006-6 | Como organización, quiero registrar el consentimiento (versión del aviso, fecha) y la autorización de terceros con cada propuesta, para demostrarlos | S | H-003-2, H-006-1 |
| H-006-7 | Como autor, quiero un formulario público accesible que conserve lo que escribí ante un error, para enviar mi propuesta sin perder el trabajo | M | H-006-1, H-005-2, H-004-3 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel (sin `composer.json` ni `artisan`). Este plan parte de los planes 001 (`Contact` con tenant y el middleware `SetOrganizationFromRoute`), 003 (aviso de privacidad versionado), 004 (convocatoria y ejes) y 005 (formulario, `BuildSubmissionRules` y `FormRenderer`), y se integra con el 007 (cargas pendientes) y el 008 (catálogo de correos). En el cpanel de origen el formulario público creaba la propuesta y el usuario del autor (categoría 5), el acuse vivía fuera del panel y no había límite de peticiones ni registro de consentimiento (`docs/producto/cpanel-actual.md`).

## 3. Decisiones

### DA-1 · Título y eje como bloque fijo — se recomienda **título, eje y participantes fijos; todo lo demás dinámico**

El listado, los filtros y el panel (plan 009) necesitan un título y un eje en columnas. El resto de los datos de la propuesta viene del formulario dinámico. `[PENDIENTE: ¿el eje es obligatorio en toda convocatoria?]` (por defecto, sí).

### DA-2 · Datos de participantes — se recomienda **instantánea en la propuesta y vínculo a `Contact` sin sobrescribirlo**

Cada participante guarda sus datos en `submission_participants` (nombre, correo, país, cargo, empresa, biografía) y se vincula a un `Contact` por correo dentro de la organización. Un envío público **nunca modifica** los datos de un `Contact` existente: cualquiera podría alterar la ficha de otra persona conociendo su correo.

### DA-3 · Enlace de acceso del autor — se recomienda **un solo uso, 30 minutos, consumo por POST y sesión separada**

El correo lleva un enlace a una página de confirmación (GET, sin efectos); el token se consume solo con el POST del botón, de forma atómica. Así los escáneres de correo que abren enlaces no lo gastan. El token tiene al menos 128 bits generados con `random_bytes`, se guarda solo como hash y se compara en tiempo constante. Tras consumirlo se regenera la sesión y se abre una sesión de autor con un guard `author` distinto del del panel, acotada a esa propuesta. Las rutas con token responden `Referrer-Policy: no-referrer` y `Cache-Control: no-store`. **Ningún correo (resultados incluidos) lleva un enlace de acceso**: los correos de resultado apuntan a la página «pedir mi enlace». Valores propuestos: caducidad del enlace 30 minutos y de la sesión del autor 60 minutos. `[PENDIENTE: ajustar los plazos tras el primer ensayo]`

### DA-4 · Falla del servicio anti-robots — se recomienda **cerrar el acceso (no aceptar) y avisar al equipo**

Si Turnstile no responde, el envío se rechaza con un mensaje que invita a reintentar y se registra la falla sin datos personales. Aceptar sin verificar abriría la puerta al spam; el riesgo es perder envíos cerca del cierre, que se mitiga con alerta. El token de Turnstile se verifica y se consume solo en el envío final; las subidas usan el token de carga (DA-9).

### DA-5 · Límites iniciales — se recomienda **5 envíos por IP cada 10 minutos, 3 acuses por correo cada hora, 3 solicitudes de enlace por correo cada hora y 10 por IP cada hora**

Se nombran en configuración (`public-submission`, `received-per-email`, `access-link-request`). Una IP compartida en un congreso puede alcanzar el límite. `[PENDIENTE: ajustar con el ensayo de carga del plan 010]`

### DA-6 · Cambiar el correo principal — se recomienda **no permitirlo al autor en R0**

Con un enlace comprometido, cambiar el correo redirigiría el acceso. Lo corrige un organizador.

### DA-7 · Retirar tras el cierre — se recomienda **permitirlo mientras el estado sea `submitted`**

El retiro no depende de que la convocatoria esté abierta. Revertirlo corresponde al organizador (plan 012).

### DA-8 · Terceros y cierre — se recomienda **declaración del autor y sin aviso a los participantes en R0; cierre exacto sin gracia**

Un autor puede dar de alta a otros participantes. En R0 declara que cuenta con su autorización y no se les envía correo (se evita usar la plataforma para escribir a terceros); el aviso a participantes secundarios se revisa en R1 `[PENDIENTE: decisión sobre avisar a los participantes]`. El cierre es exacto (`closes_at` excluido, plan 004 DA-5), sin periodo de gracia.

### DA-9 · Carga de archivos antes del envío — se recomienda **token de carga emitido por el servidor**

Al renderizar el formulario, el servidor emite una **clave de idempotencia** y un **token de carga** (se guarda solo su hash en la sesión del formulario). Las cargas del plan 007 solo se aceptan con un token válido y quedan ligadas a él, con cuota por IP y por token. Así un anónimo no referencia cargas ajenas ni las reutiliza.

## 4. Contrato

**Rutas web** (Inertia y formularios; sin endpoints JSON nuevos, no se toca `docs/api/endpoints.md`). Todas bajo `/{organization:slug}/events/{event:slug}`; el middleware `SetOrganizationFromRoute` (plan 001) establece `CurrentOrganization` **antes** del binding y de cualquier búsqueda de token, y ninguna usa `withoutOrganizationScope()`:

| Ruta | Método | Quién |
|---|---|---|
| `/call/{call:slug}/submit` | GET, POST | Público; límite `public-submission`, `received-per-email` y Turnstile |
| `/call/{call:slug}/uploads` | POST | Público; lo implementa el plan 007; exige el token de carga (DA-9) |
| `/call/{call:slug}/submitted` | GET | Público, confirmación sin datos personales (usa un dato de sesión, sin identificador en la URL) |
| `/my-submission/request-link` | GET, POST | Público; límite `access-link-request` y Turnstile |
| `/my-submission/access/{token}` | GET (confirmación sin efectos), POST (consume) | Público; `Referrer-Policy: no-referrer` |
| `/my-submission` | GET | Sesión de autor (guard `author`) |
| `/my-submission/edit` | GET, PATCH | Sesión de autor |
| `/my-submission/withdraw` | POST | Sesión de autor |

**Form Requests:** `StoreSubmissionRequest` (compone las reglas de `BuildSubmissionRules` con el bloque fijo, los participantes, el consentimiento, la declaración de terceros, la clave de idempotencia, la `form_version` y el token del anti-robots), `RequestAccessLinkRequest`, `ConsumeAccessLinkRequest`, `UpdateSubmissionRequest`, `WithdrawSubmissionRequest`.

**Actions:** `SubmitSubmission` (carga el formulario con `LockedFormDefinition`, dentro de una transacción), `IssueFormSession` (clave de idempotencia y token de carga), `CreateAccessToken`, `SendAccessLink`, `ConsumeAccessToken`, `UpdateSubmissionByAuthor`, `WithdrawSubmission`, `VerifyTurnstile` (cliente HTTP de Laravel, sin paquete nuevo). **Servicio de apoyo:** `AuthorSession` (sesión del guard `author` acotada a una propuesta).

**Correos** (se registran en el catálogo del plan 008, claves del plan maestro §7.5): `submission.received` (acuse; no lleva enlace de acceso), `submission.access_link`, `submission.new_for_organizers`, `submission.updated`, `submission.withdrawn`.

**Contratos con otros planes:** `PendingUploadRepository` (plan 007) para validar y adjuntar cargas con el token de carga; `SubmissionAnswerCounter` (plan 005) y `TrackUsageChecker` (plan 004), cuyas implementaciones reales enlaza este plan; `LockedFormDefinition` (plan 005); la tabla `privacy_notice_versions` del plan 003.

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** guardar lo que envía una persona a una convocatoria, quiénes participan, bajo qué aviso consintió, con qué formulario respondió y con qué acceso la consulta. Módulo dueño: propuestas. Ciclo de vida: enviada, editable mientras la convocatoria esté abierta, retirada; se conserva como evidencia.
2. **Inspección:** sin esquema previo. Se parte de `ponencias`, `usuarios_ponencias` y `aspirantes` del cpanel como referencia funcional; no se reutilizan.
3. **Modelo:**
   - `submissions`: `id uuid`, `organization_id`, `event_id`, `call_for_submissions_id`, `form_definition_id`, `form_version int`, `form_snapshot jsonb` (copia de la definición enviada; máximo 64 KB, el límite de la definición), `track_id` (eje), `title varchar(200)`, `answers jsonb`, `status` (con `CHECK` sobre **todos** los estados del glosario: `submitted`, `under_review`, `accepted`, `waitlisted`, `rejected`, `confirmed`, `withdrawn`; en R0 solo se producen `submitted` y `withdrawn`), `locale`, `idempotency_key` y `payload_hash` (únicos por organización la clave), `privacy_notice_version_id`, `consented_at`, `third_parties_authorized_at` (nulo si hay un solo participante), `submitted_at`, `withdrawn_at`, `version integer` (bloqueo optimista de la propuesta), `timestamps`, `SoftDeletes`. Ámbito: evento.
   - `submission_participants`: `id`, `organization_id`, `submission_id`, `contact_id`, `position`, `is_primary`, `full_name varchar(120)`, `email varchar(254)`, `country` (ISO 3166-1 alfa-2), `job_title varchar(120)`, `company varchar(160)`, `bio varchar(2000)`. Ámbito: evento.
   - `submission_access_tokens`: `id`, `organization_id`, `submission_id`, `token_hash` (SHA-256, único), `expires_at`, `consumed_at`, `timestamps`. Solo se guarda el hash. Ámbito: evento.
   - `contacts` lo fija el plan 001 (`full_name`, `email`, `country`, `unique(organization_id, lower(email))`); este plan **no** lo migra.
   - `call_for_submissions` (plan 004): se añaden `min_participants` y `max_participants` (por defecto 1 y 5), editables en el panel por quien tenga `calls.manage`.
4. **Integridad:** claves foráneas compuestas con `organization_id` entre `submissions`, `submission_participants`, `submission_access_tokens`, `tracks` y `call_for_submissions` (que declaran `unique(id, organization_id)`), para impedir referencias entre organizaciones; `track_id` con clave foránea restrictiva; `unique(organization_id, idempotency_key)`; un solo participante principal por propuesta (índice parcial `is_primary`); `CHECK (status IN (...))` con los siete estados; longitudes máximas como tipos `varchar(n)`; índices `(organization_id, event_id, call_for_submissions_id, status)` y `(organization_id, track_id)`.
5. **Cambio:** migraciones nuevas sin datos previos; `down()` en todas. La aplica el humano.
6. **Alternativa descartada:** guardar los participantes como lista dentro de `answers` (`jsonb`). Se descartó porque se consultan, se vinculan a `Contact` por correo, alimentarán el CRM y piden restricciones (un principal, correo obligatorio) que el `jsonb` no garantiza. También se descartó crear una cuenta de usuario por autor (como el cpanel): añade contraseñas y superficie de ataque sin necesidad en R0, y guardar solo `form_version` sin copia de la definición, porque las etiquetas editadas cambiarían retroactivamente el significado de lo enviado.
7. **Preguntas de negocio:** DA-1 (eje obligatorio), DA-8 (aviso a participantes) y los plazos de DA-3.

**Datos personales:** se añaden al inventario de `docs/privacidad.md`: nombre, correo, país, cargo, empresa y biografía de participantes, incluidos los de terceros dados de alta por el autor (finalidad: evaluación y programa; base legal: consentimiento del titular, declarado por el autor para terceros; conservación `[PENDIENTE: plazo]`, que fija el plan 014); versión del aviso y fecha de consentimiento; fecha de la declaración de autorización de terceros.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Autor (sin cuenta): enviar, consultar, editar y retirar **solo su propuesta** con enlace de un solo uso. Organizadores: leer propuestas con `submissions.view` (panel, plan 009) y exportarlas con `submissions.export`. Nadie del público lista ni abre propuestas ajenas |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` para el equipo; el autor se autoriza con `AuthorSession` (guard `author`, su sesión nombra una sola propuesta) y una regla explícita en `SubmissionPolicy`. `tests/Feature/Submissions/` por rol y acción (permitido y denegado) |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `Submission`, `SubmissionParticipant`, `SubmissionAccessToken` con `BelongsToOrganization`; el contexto público lo establece `SetOrganizationFromRoute` desde el `slug` de la URL. Cubiertos por las guardianas |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; el autor accede por su propuesta, no por evento. Todo con `forEvent($event)` |
| ¿Datos personales nuevos? | Sí (ver §4), incluidos datos de terceros. Base legal: consentimiento registrado con versión y fecha y declaración del autor para terceros. El correo nunca revela si existe una propuesta (respuesta homogénea). Sin datos personales en logs |
| ¿Se suben archivos? | Los sube el plan 007. Este plan emite el token de carga y solo referencia cargas pendientes ligadas a él y a la misma organización; tipos, tamaño y disco privado son del 007 |
| ¿Dinero, pagos o webhooks? | No. Sí hay una verificación saliente a Turnstile con la llave secreta en configuración |
| ¿Se envían correos o notificaciones? | Cinco claves del catálogo (ver §4), por cola; el acuse es crítico y no se desactiva |
| ¿Qué se registra en la auditoría? | Envío, edición (valores anterior y nuevo, sin PII completa), retiro, solicitud de enlace (sin el correo en claro) y consumo del token. El causante es el autor (referencia a su `Contact`), no un usuario |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spikes S-6 (Turnstile y límites) y S-7 (enlace de acceso); migraciones y modelos; contratos con los planes 004, 005, 007 y 008 | S |
| F1 | `SubmitSubmission` con transacción, bloqueo del formulario, instantánea, idempotencia, participantes, eje y contacto (H-006-1) | M |
| F2 | Consentimiento con versión y declaración de terceros (H-006-6) | S |
| F3 | Límite de peticiones, Turnstile y token de carga (H-006-2) | S |
| F4 | Formulario público con `FormRenderer`, estados de la convocatoria y accesibilidad AA (H-006-7) | M |
| F5 | Acuse y enlace de acceso: creación, envío, consumo atómico, sesión del autor y vista (H-006-3) | M |
| F6 | Edición y retiro por el autor (H-006-4, H-006-5; «Debería») | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-006-n: ...`) o declara su comprobación manual.

**H-006-1 · Enviar la propuesta (backend)**

- **CA-006-1:** *Dado* una convocatoria efectivamente abierta con formulario publicado, un eje del evento y un aviso vigente, *cuando* un autor envía título, eje, un participante principal, respuestas válidas y su consentimiento, *entonces* se crea una propuesta `submitted` con sus participantes y respuestas, vinculada a un `Contact` de la organización, y la respuesta es la página de confirmación.
- **CA-006-2:** *Dado* una convocatoria `scheduled`, `paused` o `closed` (incluido un cierre de emergencia), *cuando* se envía, *entonces* se rechaza con `CALL_NOT_OPEN` y no se guarda nada; *cuando* la convocatoria está en `draft`, es inexistente o de otra organización, *entonces* la respuesta es 404.
- **CA-006-3:** *Dado* una convocatoria con `closes_at` = T, *cuando* el instante del servidor es T − 1 s, *entonces* el envío se acepta; *cuando* es T exacto o posterior, *entonces* se rechaza con `CALL_NOT_OPEN`, aunque el formulario se haya cargado antes del cierre (sin periodo de gracia, DA-8).
- **CA-006-4:** *Dado* respuestas que no cumplen la definición del formulario (campo obligatorio vacío, tipo, rango u opción inválidos, clave desconocida), *cuando* se envía, *entonces* responde 422 con el error asociado a cada campo y no se guarda nada.
- **CA-006-5:** *Dado* una convocatoria con `min_participants` 1 y `max_participants` 3, *cuando* llegan cero o cuatro participantes, un participante sin nombre, correo válido o país, ningún principal, o dos con el mismo correo, *entonces* se rechaza.
- **CA-006-6:** *Dado* el bloque fijo, *cuando* el título supera 200 caracteres, el nombre 120, el cargo 120, la empresa 160, la biografía 2000 o el correo 254, *entonces* se rechaza con 422 por campo, y *cuando* un valor contiene etiquetas HTML, *entonces* se guarda como texto y se muestra siempre escapado.
- **CA-006-7:** *Dado* un cuerpo que incluye `organization_id`, `event_id`, `status`, `submitted_at` o `call_for_submissions_id` del cliente, *cuando* se envía, *entonces* esos valores se ignoran: la organización, el evento y la convocatoria salen de la ruta y del contexto, y el estado es siempre `submitted`.
- **CA-006-8:** *Dado* un `track_id` de otro evento, de otra organización o archivado, *cuando* se envía, *entonces* se rechaza.
- **CA-006-9:** *Dado* un fallo al crear los participantes o los vínculos, *cuando* se envía, *entonces* la transacción revierte todo, no queda propuesta parcial y no se encola ningún correo.
- **CA-006-10:** *Dado* un correo de participante que ya existe como `Contact` de la organización, *cuando* se envía, *entonces* se reutiliza ese `Contact` sin modificar sus datos; *cuando* el correo existe solo en otra organización, *entonces* se crea un `Contact` nuevo en la de la propuesta.
- **CA-006-11:** *Dado* una convocatoria sin formulario publicado, *cuando* se intenta enviar, *entonces* se rechaza con `CALL_NOT_READY`.
- **CA-006-12:** *Dado* un envío cuya `form_version` no es la vigente del formulario, *cuando* se envía, *entonces* responde 409 `FORM_OUTDATED` con un mensaje que pide recargar el formulario y no se guarda nada; *cuando* la edición del formulario y el envío ocurren al mismo tiempo (dos transacciones en paralelo sobre `LockedFormDefinition`), *entonces* la propuesta guardada cumple la definición cuya versión quedó registrada con ella.
- **CA-006-13:** *Dado* un envío aceptado, *cuando* se guarda, *entonces* la propuesta conserva `form_version` y `form_snapshot` (copia de la definición vigente, de 64 KB como máximo), y una edición posterior de las etiquetas del formulario no altera la instantánea de propuestas ya enviadas.
- **CA-006-14:** *Dado* una clave de idempotencia emitida por el servidor al renderizar el formulario, *cuando* llega dos veces con la misma carga (doble clic o reintento), *entonces* existe una sola propuesta, se encola un solo correo y la segunda respuesta es la misma confirmación.
- **CA-006-15:** *Dado* una clave de idempotencia ya usada, *cuando* llega con una carga distinta (`payload_hash` diferente), *entonces* responde 409 y no cambia nada; *cuando* la clave no fue emitida por el servidor, *entonces* responde 422.
- **CA-006-16:** *Dado* dos peticiones simultáneas con la misma clave de idempotencia (dos conexiones), *cuando* ambas terminan, *entonces* existe exactamente una propuesta y ninguna respuesta es un error 500 (la violación de unicidad se resuelve a la misma confirmación).
- **CA-006-17:** *Dado* un eje con propuestas, *cuando* `TrackUsageChecker` (implementación real) lo consulta o se intenta borrarlo directamente en la base, *entonces* se informa en uso y la clave foránea restrictiva impide el borrado.
- **CA-006-18:** *Dado* una petición pública a la URL de la organización A, *cuando* se resuelve la ruta, *entonces* `CurrentOrganization` queda establecido por `SetOrganizationFromRoute` antes del binding, el `slug` de una convocatoria de B bajo la organización A responde 404, y una prueba de arquitectura falla si estos controladores llaman a `withoutOrganizationScope()`.
- **CA-006-19:** *Dado* la base de datos, *cuando* se inserta directamente una propuesta, un participante o un token con el `organization_id` de A y una convocatoria o propuesta de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada).

**H-006-2 · Límite de peticiones, anti-robots y cargas**

- **CA-006-20:** *Dado* una misma IP, *cuando* supera el límite `public-submission` (DA-5), *entonces* recibe 429 con `Retry-After` y un mensaje en pantalla, y no se crea propuesta.
- **CA-006-21:** *Dado* un envío sin token de Turnstile o con uno que el servicio declara inválido (simulado con `Http::fake()`), *cuando* se envía, *entonces* se rechaza con 422 y no se guarda nada.
- **CA-006-22:** *Dado* que el servicio de Turnstile no responde o supera el tiempo de espera, *cuando* se envía, *entonces* se rechaza con un mensaje para reintentar (DA-4), se registra la falla sin datos personales y no se guarda nada.
- **CA-006-23:** *Dado* la página del formulario, *cuando* se renderizan las props, *entonces* incluyen la llave pública de Turnstile y no la secreta, y la verificación usa la llave secreta solo desde la configuración del servidor.
- **CA-006-24:** *Dado* un token de Turnstile ya usado en un envío, *cuando* se reutiliza en otro, *entonces* se rechaza con 422; y la verificación ocurre solo en el envío final, no en las cargas.
- **CA-006-25:** *Dado* solicitudes de enlace de acceso, *cuando* se supera el límite por correo (calculado sobre el correo normalizado y con hash) o por IP, *entonces* recibe 429 sin distinguir si el correo existe.
- **CA-006-26:** *Dado* un correo de participante principal que ya recibió 3 acuses en la última hora, *cuando* se envía otra propuesta con ese correo, *entonces* se rechaza con 429, no se crea la propuesta y no se encola `submission.received`.
- **CA-006-27:** *Dado* el formulario renderizado, *cuando* el servidor emite el token de carga, *entonces* solo se guarda su hash en la sesión del formulario; *cuando* una carga llega sin token, con uno inexistente o con el de otra sesión, *entonces* se rechaza con 403 `UPLOAD_TOKEN_INVALID`; y un identificador de carga ligado a otro token no puede adjuntarse al enviar la propuesta.
- **CA-006-28:** *Dado* un token de carga, *cuando* supera sus cuotas (por token y por IP, valores en configuración; `[PENDIENTE: definir con el plan 007]`), *entonces* recibe 429 y no se guarda el archivo.

**H-006-3 · Acuse y enlace de acceso**

- **CA-006-29:** *Dado* un envío correcto, *cuando* termina la transacción, *entonces* se encola `submission.received` al correo del participante principal (sin enlace de acceso) y se encola `submission.new_for_organizers` para los usuarios con `submissions.view` de la organización.
- **CA-006-30:** *Dado* un correo que es el principal de una propuesta del evento, *cuando* pide un enlace en `/my-submission/request-link`, *entonces* se crea un token con caducidad y se encola `submission.access_link`; el correo recibe un enlace por propuesta activa, con un máximo de cinco.
- **CA-006-31:** *Dado* un correo sin propuestas, *cuando* pide un enlace, *entonces* no se crea token ni se envía correo.
- **CA-006-32:** *Dado* un correo con propuestas y uno sin ellas, *cuando* piden un enlace, *entonces* el código de estado y el cuerpo de la respuesta son idénticos, y en ambos casos la petición responde sin ejecutar ningún envío síncrono (la prueba afirma que solo se encola trabajo).
- **CA-006-33:** *Dado* un token generado, *cuando* se inspecciona, *entonces* tiene al menos 128 bits de `random_bytes`, solo existe su hash SHA-256 en la base, la comparación usa `hash_equals`, y el valor en claro no aparece en tablas ni en logs de aplicación.
- **CA-006-34:** *Dado* un enlace válido, *cuando* se abre con GET, *entonces* solo muestra la confirmación y no consume el token; *cuando* se confirma con POST, *entonces* se consume, se regenera el identificador de sesión, se abre la sesión del autor y el mismo enlace deja de funcionar.
- **CA-006-35:** *Dado* dos POST simultáneos con el mismo token (dos conexiones), *cuando* ambos terminan, *entonces* exactamente uno abre sesión (`UPDATE ... WHERE consumed_at IS NULL` afecta una fila) y el otro recibe el mensaje genérico de enlace no válido.
- **CA-006-36:** *Dado* un token ya consumido, caducado, alterado, inexistente o de otra organización (buscado con el contexto de la ruta), *cuando* se usa, *entonces* se muestra el mismo mensaje genérico de enlace no válido, sin revelar la causa.
- **CA-006-37:** *Dado* una sesión del guard `author`, *cuando* intenta abrir una página del panel de organizadores, *entonces* se deniega; y *cuando* un usuario del panel intenta usar rutas de `/my-submission`, *entonces* no se le concede acceso como autor.
- **CA-006-38:** *Dado* una sesión de autor de la propuesta A, *cuando* se intenta ver, editar o retirar la propuesta B por identificador, *entonces* la respuesta es 404, y sin sesión se pide un enlace nuevo.
- **CA-006-39:** *Dado* las rutas con token, *cuando* responden, *entonces* incluyen `Referrer-Policy: no-referrer` y `Cache-Control: no-store`, y el token de la URL no se registra en los logs de aplicación.
- **CA-006-40:** *Dado* un autor con sesión, *cuando* consulta su propuesta, *entonces* ve sus respuestas, participantes y estado, y no ve datos exclusivos de organizadores (notas, evaluaciones, otras propuestas).

**H-006-4 · Editar la propuesta («Debería»)**

- **CA-006-41:** *Dado* una propuesta `submitted` y una convocatoria efectivamente abierta, *cuando* el autor edita con datos válidos, *entonces* se guardan, la `version` aumenta, queda auditoría con valores anterior y nuevo y se encola `submission.updated`.
- **CA-006-42:** *Dado* una convocatoria cerrada, pausada o aún no abierta (incluido el instante exacto del cierre), *cuando* el autor intenta editar, *entonces* se rechaza con `CALL_NOT_OPEN` y no cambia nada.
- **CA-006-43:** *Dado* dos ediciones simultáneas, *cuando* la segunda guarda con una `version` anterior, *entonces* se rechaza con conflicto y no pisa la primera.
- **CA-006-44:** *Dado* una edición, *cuando* se valida, *entonces* usa `BuildSubmissionRules` con la definición vigente como el envío, los campos retirados no se piden, se actualizan `form_version` y `form_snapshot`, y una `form_version` obsoleta responde 409 `FORM_OUTDATED`.
- **CA-006-45:** *Dado* una edición que intenta cambiar el correo del participante principal, el estado, la convocatoria o la organización, *cuando* se envía, *entonces* esos cambios se ignoran o se rechazan (DA-6).

**H-006-5 · Retirar la propuesta («Debería»)**

- **CA-006-46:** *Dado* una propuesta `submitted`, *cuando* el autor la retira con POST y CSRF, *entonces* pasa a `withdrawn` con `withdrawn_at`, se encola `submission.withdrawn` al autor y a los organizadores y queda auditado.
- **CA-006-47:** *Dado* una propuesta ya `withdrawn`, *cuando* se retira de nuevo, *entonces* no cambia nada ni se envía otro correo (idempotente).
- **CA-006-48:** *Dado* una propuesta `withdrawn`, *cuando* el autor intenta editarla, *entonces* se rechaza; una petición GET a la acción de retiro responde 405.
- **CA-006-49:** *Dado* una propuesta retirada, *cuando* un organizador la consulta, *entonces* sigue visible con su estado `withdrawn`; no se borra físicamente.

**H-006-6 · Consentimiento y terceros**

- **CA-006-50:** *Dado* el formulario, *cuando* se envía sin aceptar el aviso de privacidad, *entonces* se rechaza con error asociado a la casilla y no se guarda nada.
- **CA-006-51:** *Dado* un envío válido, *cuando* se guarda, *entonces* la propuesta conserva el identificador de la versión del aviso, `consented_at` en UTC y el idioma del envío, y no se guardan datos de más (sin IP).
- **CA-006-52:** *Dado* un formulario abierto con una versión del aviso que ya no es la vigente, *cuando* se envía, *entonces* se rechaza con 422 y se muestra el texto vigente para aceptarlo de nuevo.
- **CA-006-53:** *Dado* un evento sin aviso publicado en el idioma de la propuesta, *cuando* se intenta enviar, *entonces* se rechaza con `CALL_NOT_READY`.
- **CA-006-54:** *Dado* un envío con más de un participante, *cuando* el autor no marca la declaración de que cuenta con la autorización de los demás participantes, *entonces* se rechaza; *cuando* la marca, *entonces* se guarda `third_parties_authorized_at`; y con un solo participante la declaración no se exige.

**H-006-7 · Formulario público accesible**

- **CA-006-55:** *Dado* una convocatoria abierta, *cuando* se abre la página de envío, *entonces* se renderiza con `FormRenderer` y los campos del formulario publicado en el idioma del visitante; *cuando* la convocatoria no está abierta o no está lista, *entonces* se muestra el estado (`scheduled`, `paused`, `closed` o no lista) sin formulario.
- **CA-006-56:** *Dado* una respuesta 422, 409 o `CALL_NOT_OPEN` del servidor, *cuando* se muestra el error, *entonces* el formulario conserva lo que el autor escribió, asocia cada error a su campo y mueve el foco al primer error (prueba de Vitest).
- **CA-006-57:** *Dado* una confirmación tras el envío, *cuando* se abre `/submitted`, *entonces* no contiene datos personales ni identificadores de propuesta en la URL y, sin el dato de sesión del envío, redirige a la convocatoria.
- **CA-006-58:** El formulario público es accesible: etiquetas, foco visible, errores asociados a sus campos, contraste AA, manejo con teclado y sin errores en consola.
  Manual: enviar una propuesta de prueba a ~375 px y ~1280 px, recorrer con Tab y provocar errores de validación y de convocatoria cerrada.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Spam o abuso del formulario público | Límite de peticiones, Turnstile verificado en el servidor, tope de tamaño de respuestas |
| Abuso de las subidas antes del envío | Token de carga emitido por el servidor, cuotas por token e IP (DA-9) |
| Enumeración de correos de autores | Respuesta homogénea, cola para el envío y límites por correo y por IP |
| Uso del formulario para escribir a terceros | Límite de acuses por correo y sin aviso a participantes en R0 (DA-5, DA-8) |
| Enlace de acceso gastado por un escáner de correo | Consumo solo por POST y atómico (DA-3) |
| Enlace de acceso reenviado, filtrado o comprometido | Un solo uso, caducidad corta, token de 128 bits solo como hash, sesión acotada a una propuesta y guard separado, `no-referrer`, correo principal no editable (DA-3, DA-6) |
| Alterar la ficha de un `Contact` desde el formulario público | Instantánea en la propuesta y vínculo sin sobrescribir (DA-2) |
| Propuestas duplicadas por doble clic o concurrencia | Clave de idempotencia emitida por el servidor, `payload_hash` y violación de unicidad resuelta (CA-006-14 a 16) |
| Un formulario cambia mientras se envía | `LockedFormDefinition`, `FORM_OUTDATED` e instantánea por propuesta (CA-006-12, CA-006-13) |
| Pérdida de envíos si falla Turnstile o por el cierre exacto | Alerta inmediata; el formulario conserva lo escrito (H-006-7); plan B del plan maestro §6.4 |
| Los planes 004, 005, 007 y 008 no están listos | Contratos con dobles; los CA de correo se verifican con `Mail::fake()` hasta que exista el catálogo |
| H-006-1 con 19 criterios es el borde de M | Si F1 la excede, la idempotencia (CA-006-14 a 16) se separa en una historia S sin cambiar los criterios |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
