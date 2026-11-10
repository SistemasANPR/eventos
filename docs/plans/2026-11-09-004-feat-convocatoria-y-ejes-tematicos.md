# feat: convocatoria y ejes temáticos

> Plan de la épica E-04 (R0). Es la especificación de SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. Fuente: `docs/plan-de-desarrollo.md` §10.1.

- **Fecha:** 2026-11-09
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Que el organizador de un evento cree su convocatoria (periodo en que el evento recibe propuestas de un tipo) con sus fechas de apertura, cierre y resultados, sus idiomas y sus **ejes temáticos propios**, la publique, y que el público la vea con su estado real. Nada del evento viene fijo: ejes, fechas y textos los captura el organizador desde el panel. **La convocatoria abre y cierra por fecha**; el control manual (pausar, cerrar, reabrir) es «Debería» (H-004-4) y el cierre de emergencia en R0 se hace moviendo `closes_at` al instante actual.

**Dentro:** modelos `CallForSubmissions`, `Track` y `SubmissionType` (tipos de propuesta propios de cada evento); recursos de Filament para ambos; publicación con validación del aviso de privacidad; estado calculado por fecha; página pública de la convocatoria (Inertia, sin SSR); contrato `TrackUsageChecker`; Policies y permisos; auditoría; estado manual como «Debería».

**Fuera:** el formulario de la convocatoria (plan 005), el envío de propuestas y la implementación real de `TrackUsageChecker` (plan 006), el aviso de privacidad (plan 003), la evaluación y los resultados (R1), el recordatorio de cierre (plan 012), SSR (RC-5), periodo de gracia al cierre y múltiples rondas de una misma convocatoria.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-004-1 | Como organizador, quiero crear una convocatoria con tipo de propuesta, fechas de apertura, cierre y resultados e idiomas, y publicarla, para recibir propuestas | M | H-003-1, H-003-2 |
| H-004-2 | Como organizador, quiero crear, ordenar y traducir los ejes temáticos de mi evento, para clasificar propuestas | S | H-003-1 |
| H-004-3 | Como visitante, quiero ver la convocatoria con fechas, ejes y estado en mi idioma, para decidir si envío | M | H-004-1, H-004-2 |
| H-004-4 | Como organizador, quiero pausar, cerrar y reabrir la convocatoria a mano, para controlar el envío («Debería») | S | H-004-1 |

## 2. Punto de partida

Al 2026-11-09 el repositorio contiene solo la plantilla: no existen `composer.json`, `artisan` ni migraciones. Este plan parte de lo que entregan los planes 001 (organización, contexto, roles y el middleware `SetOrganizationFromRoute`), 002 (esqueleto con página pública Inertia sin SSR) y 003 (`Event` con zona horaria, idiomas y aviso de privacidad versionado). En el cpanel de origen los ejes eran un catálogo oculto en el menú y la convocatoria no tenía fechas configurables (`docs/producto/cpanel-actual.md`).

El cierre de la convocatoria 2027 es el 21-ene-2027, pero lo captura el organizador en la plataforma, no se siembra. `[PENDIENTE: fecha de resultados de la convocatoria 2027]`.

## 3. Decisiones

### DA-1 · Estado manual y estado efectivo — se recomienda **`status` manual más un estado efectivo calculado**

`status` (`draft`, `published`, `paused`, `closed`) lo cambia una persona. En R0 solo se usan `draft` y `published`; `paused` y `closed` llegan con H-004-4. El estado que ve el público (`CallState`: `scheduled`, `open`, `paused`, `closed`) se **calcula en cada petición** a partir de `status` y de las fechas, sin depender del planificador: con `status = published`, es `scheduled` antes de `opens_at`, `open` desde `opens_at` (incluido) y `closed` desde `closes_at` (el cierre es exclusivo). Una convocatoria abre y cierra a su hora aunque falle un *worker*.

### DA-2 · Varias convocatorias por evento — se recomienda **permitirlas**

Un evento puede tener una convocatoria por tipo (sesión, panel, póster) o varias del mismo tipo con distinto formulario. `slug` es único por evento, no el tipo.

### DA-3 · Quién gestiona — se recomienda **administrador de organización gestiona; miembro solo consulta**

Permisos: `calls.view`, `calls.manage`, `tracks.manage` (nombres de la matriz del plan 001). El administrador los tiene todos; el miembro, solo `calls.view`. `[PENDIENTE: ¿el rol de coordinador de evento puede gestionar convocatorias en R0?]`

### DA-4 · Ejes con propuestas — se recomienda **archivar, no eliminar**

Un eje con propuestas no se borra: se archiva (`archived_at`), deja de ofrecerse en nuevos envíos y se conserva en las propuestas existentes. Si hay propuestas lo decide el contrato `TrackUsageChecker`, que aquí tiene una implementación nula y un doble en las pruebas; el plan 006 enlaza la real y la clave foránea restrictiva.

### DA-5 · Sin periodo de gracia al cierre — se recomienda **cierre estricto en R0**

El cierre es exacto: con el instante del servidor en `closes_at` o después, no se aceptan envíos (plan 006). No hay margen de gracia. Un organizador que quiera dar más tiempo mueve `closes_at` antes del cierre.

### DA-7 · Tipos de propuesta — se recomienda **una lista propia de cada evento, gestionable por el organizador**

El tipo de propuesta (sesión, panel, póster, taller…) deja de ser una lista fija en código: es una tabla `submission_types` por evento, con nombre traducible, orden y archivado (como los ejes, DA-4). Cada evento nuevo nace con Sesión, Panel, Póster y Taller (es, en, pt), que el organizador puede renombrar, reordenar, archivar o ampliar. Al elegir el tipo en una convocatoria hay un acceso para crear uno nuevo en el momento y una sección «Tipos de propuesta» dentro del evento para gestionarlos. Un tipo archivado no se ofrece en convocatorias nuevas pero se conserva en las existentes. Quien gestiona es quien ya gestiona convocatorias (`calls.manage`; consulta con `calls.view`): no hay permisos nuevos. No hay catálogo compartido entre eventos ni borrado (un tipo en uso es evidencia).

### DA-6 · Publicar exige aviso de privacidad — se recomienda **bloquear la publicación sin aviso**

Una convocatoria solo pasa a `published` si el evento tiene el aviso de privacidad **publicado en cada uno de sus idiomas** (plan 003). Evita abrir sin un texto que consentir (plan 006, H-006-6).

## 4. Contrato

**Rutas web** (sin endpoints JSON nuevos; no se toca `docs/api/endpoints.md`):

| Ruta | Método | Quién |
|---|---|---|
| `/{organization:slug}/events/{event:slug}/call/{call:slug}` (`calls.show`) | GET | Público; `scopeBindings` acotado a la organización; `SetOrganizationFromRoute` (plan 001) establece el contexto antes del binding |
| Recursos de Filament `CallForSubmissionsResource` y `TrackResource` | — | Equipo con permiso |
| Acciones de Filament `PublishCall`, y (H-004-4) `PauseCall`, `CloseCall`, `ReopenCall` | POST/PATCH con CSRF | `calls.manage` |

**Form Requests:** `StoreCallForSubmissionsRequest`, `UpdateCallForSubmissionsRequest`, `PublishCallRequest`, `StoreTrackRequest`, `UpdateTrackRequest`, `ReorderTracksRequest`, `ChangeCallStatusRequest`.

**Actions:** `CreateCallForSubmissions`, `UpdateCallForSubmissions`, `PublishCall`, `ChangeCallStatus`, `CreateTrack`, `UpdateTrack`, `ReorderTracks`, `ArchiveTrack`. Clase de apoyo `ResolveCallState` (puro, probado con `Carbon::setTestNow`). **Contratos:** `TrackUsageChecker` (nulo aquí, real en el plan 006) y el contrato del plan 003 que informa si el evento tiene aviso publicado por idioma (nombre definitivo en el plan 003).

**Tipos de propuesta (DA-7):** Actions `CreateSubmissionType`, `RenameSubmissionType`, `MoveSubmissionType`, `ArchiveSubmissionType` y `RestoreSubmissionType`, más `SeedDefaultSubmissionTypes` que ejecuta `CreateEvent`. Policy `SubmissionTypePolicy` con los permisos de convocatorias. Sin rutas nuevas: solo Filament (sección del evento y acceso desde el selector de la convocatoria).

**Props de Inertia** (`Calls/Show`): título, descripción, tipo, idiomas, `state`, `opensAt`, `closesAt`, `resultsAt` (ISO 8601 en UTC) y `tracks[]` activos. Los tipos TypeScript se escriben a mano en `resources/js/types/`.

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** el evento recibe propuestas dentro de un periodo y las clasifica por eje. Módulo dueño: convocatoria. Ciclo de vida: de borrador a cerrada; se conserva como evidencia.
2. **Inspección:** no hay esquema previo (proyecto sin migraciones). Se revisó la tabla `temas` y el catálogo de ejes del cpanel como referencia funcional, sin reutilizarlos.
3. **Modelo:**
   - `call_for_submissions`: `id uuid`, `organization_id uuid NOT NULL`, `event_id uuid NOT NULL`, `slug` (lo genera el sistema, CA-004-28), `submission_type_id uuid NOT NULL` (clave foránea compuesta `(submission_type_id, organization_id)` hacia `submission_types`, restrictiva; DA-7), `title` y `description` (`jsonb` traducible), `locales` (`jsonb` con códigos de idioma, validado contra los del evento), `opens_at`, `closes_at`, `results_at` (`timestamptz`, esta última nula hasta definirla), `status` (`draft`, `published`, `paused`, `closed`, con `CHECK`), `timestamps` y `SoftDeletes`. Ámbito: evento.
   - `submission_types` (DA-7): `id uuid`, `organization_id`, `event_id`, `name` (`jsonb` traducible), `position int`, `archived_at`, `timestamps`. Ámbito: evento. `unique(id, organization_id)`, clave foránea compuesta `(event_id, organization_id)` hacia `events`, índice `(organization_id, event_id, position)` y disparador que impide cambiar `organization_id`. Sin `SoftDeletes`: no se borra, se archiva.
   - `tracks`: `id uuid`, `organization_id`, `event_id`, `name` y `description` (`jsonb` traducible), `position int`, `archived_at`, `timestamps`. Ámbito: evento.
4. **Integridad:** `organization_id NOT NULL` con clave foránea; `unique(id, organization_id)` en ambas tablas (lo exigen las claves foráneas compuestas de los planes 005 y 006); clave foránea compuesta `(event_id, organization_id)` hacia `events` (que declara `unique(id, organization_id)` en el plan 003) para impedir cruces entre organizaciones; `unique(organization_id, event_id, slug)`; `CHECK (closes_at > opens_at)`; índices `(organization_id, event_id, status)` y `(organization_id, event_id, position)`; `status` y `submission_type` como `enum` de PHP con `CHECK`.
5. **Cambio:** migraciones nuevas. La de `submission_types` crea los cuatro tipos por defecto en cada evento existente, enlaza las convocatorias que ya tenían un tipo y elimina la columna `submission_type` y su `CHECK`; `down()` la restaura desde el nombre del tipo. `down()` elimina las tablas. La migración la aplica el humano (acción 1 de `AGENTS.md`).
6. **Alternativa descartada:** guardar los ejes como lista dentro de `events` o de la convocatoria (`jsonb`). Se descartó porque las propuestas los referencian por clave foránea, se filtran y se reportan entre eventos, y un arreglo impide integridad y archivado.
7. **Preguntas de negocio:** `[PENDIENTE: fecha de resultados de la convocatoria 2027]`; DA-3.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador de organización: crear, editar, publicar y archivar convocatorias y ejes (y pausar, cerrar y reabrir con H-004-4). Miembro: solo ver (`calls.view`). Visitante: ver la convocatoria publicada. Soporte de plataforma: sin acceso salvo `withoutOrganizationScope()` auditado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `CallForSubmissionsPolicy` y `TrackPolicy`; `tests/Feature/Calls/` con una prueba por rol y acción (permitido y denegado) |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `CallForSubmissions` y `Track`, ambos con `BelongsToOrganization`; cubiertos por `ModelsAreScopedTest` y `TenantIsolationTest`. La ruta pública no usa `withoutOrganizationScope()` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; las Policies usan el rol de organización. Cada consulta se acota con `forEvent($event)`. `event_assignments` llega en el plan 011 |
| ¿Datos personales nuevos? | No. Son textos que define la organización. Nada se añade a `docs/privacidad.md` |
| ¿Se suben archivos? | No |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos o notificaciones? | Ninguno en este plan. El recordatorio de cierre (`call.closing_reminder`) lo registra el plan 012 |
| ¿Qué se registra en la auditoría? | Alta, edición, publicación y cambios de estado de la convocatoria (quién, cuándo, valor anterior y nuevo); alta, orden y archivado de ejes (`spatie/laravel-activitylog`) |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Permisos `calls.view`, `calls.manage`, `tracks.manage` y su asignación a roles, con pruebas de rol; enums `SubmissionType`, `CallStatus`, `CallState` y `ResolveCallState` con pruebas de fronteras de fecha; contrato `TrackUsageChecker` | S |
| F1 | `Track`: migración, modelo, Policy, recurso de Filament con orden, traducción y archivado (H-004-2) | S |
| F1b | Tipos de propuesta por evento (DA-7): migración con datos por defecto, modelo, Policy, Actions, sección del evento y acceso desde el selector (CA-004-29 a 36) | M |
| F2 | `CallForSubmissions`: migración, modelo, Policy, recurso de Filament, validación de fechas e idiomas y publicación con aviso (H-004-1) | M |
| F3 | Página pública `Calls/Show` con estados e i18n (es, en), accesible a ~375 px y ~1280 px (H-004-3) | M |
| F4 | Estado manual: pausar, cerrar y reabrir con auditoría (H-004-4, «Debería») | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-004-n: ...`) o declara su comprobación manual.

**H-004-1 · Crear y publicar la convocatoria**

- **CA-004-1:** *Dado* un administrador de la organización A y un evento de A con zona horaria `America/Mexico_City`, *cuando* crea una convocatoria de tipo `session` con apertura, cierre e idiomas válidos, *entonces* queda en estado `draft` con las fechas guardadas en UTC y se muestran en la zona del evento.
- **CA-004-2:** *Dado* un formulario de convocatoria, *cuando* el cierre es igual o anterior a la apertura, o los resultados son anteriores al cierre, *entonces* se rechaza con error de validación por campo; los resultados pueden quedar vacíos.
- **CA-004-3:** *Dado* un evento con idiomas `es` y `en`, *cuando* se indica un idioma que el evento no tiene o ninguno, *entonces* se rechaza.
- **CA-004-4:** *Dado* el tipo de propuesta, *cuando* no es un tipo activo del evento de la convocatoria, *entonces* se rechaza; y el mismo título en otro evento o en otra organización se permite.
- **CA-004-5:** *Dado* un miembro sin `calls.manage`, *cuando* intenta crear, editar o publicar una convocatoria, *entonces* se deniega (403); *cuando* solo consulta con `calls.view`, *entonces* se permite.
- **CA-004-6:** *Dado* el administrador de A, *cuando* intenta crear una convocatoria con el `event_id` de un evento de B, *entonces* se rechaza, y *cuando* abre por identificador una convocatoria de B, *entonces* la respuesta es 404.
- **CA-004-7:** *Dado* una convocatoria creada, editada o publicada, *cuando* se guarda, *entonces* queda un registro de auditoría con quién, cuándo y los valores anterior y nuevo.
- **CA-004-8:** *Dado* la base de datos, *cuando* se inserta directamente una convocatoria con el `organization_id` de A y el `event_id` de un evento de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada).
- **CA-004-9:** *Dado* una convocatoria en `draft` con fechas válidas y el aviso de privacidad del evento publicado en todos sus idiomas, *cuando* un administrador la publica, *entonces* pasa a `published` y queda auditado.
- **CA-004-10:** *Dado* una convocatoria cuyo evento no tiene aviso de privacidad publicado en alguno de los idiomas de la convocatoria, *cuando* se intenta publicar, *entonces* se rechaza con `PRIVACY_NOTICE_MISSING` y sigue en `draft`.
- **CA-004-11:** *Dado* una convocatoria publicada y abierta, *cuando* el administrador cambia `closes_at` al instante actual (cierre de emergencia), *entonces* el estado público es `closed` en la petición siguiente y los envíos se rechazan; *cuando* el instante indicado es anterior o igual a `opens_at`, *entonces* se rechaza por la regla de fechas.

- **CA-004-28:** *Dado* un organizador que crea una convocatoria, *cuando* guarda, *entonces* el identificador de la URL (`slug`) se genera solo a partir del título en el idioma base del evento (o, si falta, de otro idioma; o `convocatoria` si no queda texto utilizable), sin que el organizador lo capture; si ya existe en el evento, incluso en una convocatoria eliminada, se añade un sufijo numérico (`-2`, `-3`); y *cuando* edita la convocatoria, *entonces* la URL no cambia.

**Tipos de propuesta del evento (DA-7)**

- **CA-004-29:** *Dado* un evento recién creado, *cuando* se consulta su lista de tipos, *entonces* tiene Sesión, Panel, Póster y Taller, activos y en ese orden, con nombre en es, en y pt.
- **CA-004-30:** *Dado* un administrador, *cuando* agrega un tipo con nombre en el idioma base del evento, *entonces* queda al final de la lista de ese evento, se puede elegir en sus convocatorias y no aparece en otros eventos; *cuando* falta el nombre en el idioma base, *entonces* se rechaza; un idioma adicional sin traducir cae al idioma base al mostrarse.
- **CA-004-31:** *Dado* un tipo, *cuando* el administrador lo renombra o lo sube o baja en la lista, *entonces* el cambio se guarda y las convocatorias que lo usan muestran el nombre nuevo; subir el primero o bajar el último no cambia nada.
- **CA-004-32:** *Dado* un tipo que usa alguna convocatoria, *cuando* se archiva, *entonces* deja de ofrecerse en convocatorias nuevas y de aceptarse al editar para cambiar a él, las convocatorias que ya lo tienen lo conservan, y al restaurarlo vuelve a ofrecerse.
- **CA-004-33:** *Dado* un administrador de A, *cuando* usa en una convocatoria el tipo de otro evento o de B, o abre por identificador un tipo de B, *entonces* se rechaza o responde 404; un miembro sin `calls.manage` recibe 403 al crear, renombrar, mover o archivar, y con `calls.view` puede consultar.
- **CA-004-34:** *Dado* la base de datos, *cuando* se inserta directamente un tipo con el `organization_id` de A y el `event_id` de un evento de B, o una convocatoria con el tipo de otra organización, *entonces* la clave foránea compuesta lo rechaza.
- **CA-004-35:** *Dado* un tipo creado, renombrado, movido o archivado, *cuando* se guarda, *entonces* queda auditado con quién, cuándo y los valores anterior y nuevo.
- **CA-004-36:** *Dado* el formulario de la convocatoria en el panel, *cuando* el administrador usa el acceso «crear tipo» junto al selector, *entonces* el tipo se crea para ese evento y queda seleccionado, y el miembro sin `calls.manage` no ve ese acceso.

**H-004-2 · Ejes temáticos**

- **CA-004-12:** *Dado* un evento, *cuando* el organizador crea ejes con nombre y descripción en es y en, y los reordena, *entonces* se guardan con su posición y se listan en ese orden.
- **CA-004-13:** *Dado* un eje, *cuando* falta el nombre en el idioma base del evento, *entonces* se rechaza; un idioma adicional sin traducir cae al idioma base al mostrarse.
- **CA-004-14:** *Dado* un eje que `TrackUsageChecker` (con doble en la prueba) declara en uso, *cuando* se intenta eliminar, *entonces* se rechaza con `TRACK_IN_USE` y solo se permite archivarlo; un eje archivado no aparece en la convocatoria pública y se conserva en las propuestas existentes.
- **CA-004-15:** *Dado* un administrador de A, *cuando* lista o edita ejes por identificador de un evento o eje de B, *entonces* la respuesta es 404; un miembro sin `tracks.manage` recibe 403 al modificar.
- **CA-004-16:** *Dado* la base de datos, *cuando* se inserta directamente un eje con el `organization_id` de A y el `event_id` de un evento de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada).

**H-004-3 · Convocatoria pública**

- **CA-004-17:** *Dado* una convocatoria `published` con `opens_at` = T1 y `closes_at` = T2, *cuando* el instante es T1 − 1 s, T1, T2 − 1 s o T2, *entonces* el estado público es `scheduled`, `open`, `open` y `closed` respectivamente (apertura incluida, cierre excluido).
- **CA-004-18:** *Dado* una convocatoria `published` con fechas vigentes, *cuando* corre el reloj sin ejecutarse ningún comando programado, *entonces* el estado cambia a `closed` en la petición posterior al cierre (comprobado con `Carbon::setTestNow`).
- **CA-004-19:** *Dado* un visitante, *cuando* pide la convocatoria en `es` o `en`, *entonces* ve los textos en ese idioma, y si el idioma no está definido ve el idioma base; las fechas se muestran en la zona del evento con `Intl`.
- **CA-004-20:** *Dado* una convocatoria en `draft`, de otra organización o inexistente, *cuando* un visitante pide su URL, *entonces* la respuesta es 404 sin distinguir los casos; el `slug` de una convocatoria de B bajo el `slug` de la organización A también es 404.
- **CA-004-21:** *Dado* una convocatoria publicada, *cuando* se renderiza, *entonces* muestra solo los ejes no archivados en su orden, y las props no incluyen datos del equipo, identificadores internos de organización ni la fecha de resultados cuando es nula.
- **CA-004-22:** La página pública se usa con teclado, tiene `lang`, título y encabezados correctos, contraste AA y sin errores en consola.
  Manual: abrir `/{organization}/events/{event}/call/{call}` a ~375 px y ~1280 px, recorrer con Tab, cambiar a `en`, y revisar la consola del navegador.
- **CA-004-23:** *Dado* una petición pública a la URL de la organización A, *cuando* se resuelve la ruta, *entonces* `CurrentOrganization` queda establecido por `SetOrganizationFromRoute` antes del binding del modelo, y una prueba de arquitectura falla si un controlador público llama a `withoutOrganizationScope()`.

**H-004-4 · Estado manual («Debería»)**

- **CA-004-24:** *Dado* un administrador, *cuando* pausa, cierra o reabre una convocatoria, *entonces* el cambio se hace solo por POST o PATCH con CSRF y Policy; una petición GET a esa acción responde 405 y no cambia nada.
- **CA-004-25:** *Dado* una convocatoria `published` en fechas vigentes, *cuando* se pausa, *entonces* el estado público es `paused` aunque las fechas estén vigentes, y al reabrirla vuelve a `open`.
- **CA-004-26:** *Dado* una convocatoria cuyo cierre ya pasó, *cuando* se intenta reabrirla sin cambiar `closes_at`, *entonces* se rechaza con un mensaje que pide una nueva fecha de cierre.
- **CA-004-27:** *Dado* un cambio de estado, *cuando* se aplica, *entonces* queda auditado con quién, cuándo, estado anterior y nuevo; un miembro sin `calls.manage` recibe 403.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Convocatoria que abre o cierra a destiempo por zona horaria | Fechas en UTC, zona del evento en los bordes y pruebas de frontera con reloj controlado (CA-004-17) |
| Dependencia del planificador para cerrar | El estado efectivo se calcula en la petición (DA-1) |
| Un eje borrado rompe propuestas ya enviadas | Archivado en vez de borrado (DA-4), `TrackUsageChecker` y clave foránea restrictiva (plan 006) |
| Los miembros no pueden preparar la convocatoria | DA-3 abierta con recomendación; el cambio de rol requiere especificación |
| Convocatoria publicada sin formulario listo | El envío se bloquea con `CALL_NOT_READY` (plan 006) |
| Convocatoria publicada sin aviso de privacidad | La publicación se rechaza (DA-6, CA-004-10) |
| Cierre estricto sin gracia pierde envíos de última hora | Cierre exacto documentado (DA-5); el formulario conserva lo escrito (plan 006, H-006-7) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 (H-004-2) | sin commit aún | 2026-11-10 | Prueba primero: `TrackTest` en rojo (27 fallos, clases inexistentes) y en verde después (27 pruebas, 112 aserciones, CA-004-12 a 16). Migración `2026_11_10_600000_create_tracks_table` aplicada solo a la base local. Modelo `Track`, fábrica, `TrackPolicy` (`calls.view` o `tracks.manage` para consultar; `tracks.manage` para modificar), Actions `CreateTrack`, `UpdateTrack`, `ReorderTracks` (lista completa de ids, posiciones 1..n), `ArchiveTrack`, `RestoreTrack` y `DeleteTrack`; contrato `TrackUsageChecker` con `NoTrackUsage` por defecto y doble en pruebas; `TrackInUse` (`TRACK_IN_USE`); sección «Ejes temáticos» en el evento. `tracks.manage` ya estaba en la matriz (org_admin y organizer). `composer lint` y `composer analyse` sin errores. Pendiente en las fuentes ajenas: `ModalWidthTest` (plan 015) y `TenantBypassTest` (panel de plataforma) fallan por trabajo en curso de otras sesiones |
| F3 (H-004-3) | sin commit aún | 2026-11-10 | Prueba primero: `PublicCallPageTest` en rojo (10 fallos, ruta y página inexistentes) y en verde después; `Calls/Show.test.tsx` (13 pruebas Vitest) en rojo por página inexistente y en verde después. Ruta `calls.show` con `{call:slug}` acotado por `scopeBindings` (la relación `Event::calls()` ya existía; `SetOrganizationFromRoute` fija la organización antes del binding), `ShowCallController` invocable y `PublicCallData` (props explícitas; idioma entre los de la CONVOCATORIA con caída al base del evento; `resultsAt` ausente si es nulo; solo ejes activos por `position`). Borrador, otro evento u organización, eliminada o evento sin publicar: 404. `paused`/`closed` manual se publica con su estado. Página `Calls/Show.tsx`, tipos `types/call.ts`, `formatEventInstant` en `lib/date-format.ts` y textos `call.*` en `lang/{es,en}/public.php`. Decisión: **sin botón de envío** (el formulario es de los planes 005 y 006); la insignia de estado y su texto de apoyo bastan. La insignia es local a la página (no es un componente reutilizable aún). Guardiana `tests/Arch/PublicControllersTest.php` (CA-004-23: ningún controlador público llama `withoutOrganizationScope`). Verificado en el navegador a 375 y 1280 px, consola sin errores. CA-004-22 sigue siendo `Manual:`. Pendiente: H-004-4 (F4, opcional). `analyse` solo reporta un error ajeno (`ModalWidthTest.php:86`, plan 015) |
