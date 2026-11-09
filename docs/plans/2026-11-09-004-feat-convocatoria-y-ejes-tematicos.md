# feat: convocatoria y ejes temáticos

> Plan de la épica E-04 (R0). Es la especificación de SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. Fuente: `docs/plan-de-desarrollo.md` §10.1.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Que el organizador de un evento cree su convocatoria (periodo en que el evento recibe propuestas de un tipo) con sus fechas de apertura, cierre y resultados, sus idiomas y sus **ejes temáticos propios**, y que el público la vea con su estado real. Nada del evento viene fijo: ejes, fechas y textos los captura el organizador desde el panel.

**Dentro:** modelos `CallForSubmissions` y `Track`; recursos de Filament para ambos; estado calculado por fecha y estado manual (abrir, pausar, cerrar); página pública de la convocatoria (Inertia, sin SSR); Policies y permisos nuevos; auditoría.

**Fuera:** el formulario de la convocatoria (plan 005), el envío de propuestas (plan 006), el aviso de privacidad (plan 003), la evaluación y los resultados (R1), el recordatorio de cierre (plan 012), SSR (RC-5) y múltiples rondas de una misma convocatoria.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-004-1 | Como organizador, quiero crear una convocatoria con tipo de propuesta, fechas de apertura, cierre y resultados e idiomas, para recibir propuestas | M | H-003-1 |
| H-004-2 | Como organizador, quiero crear, ordenar y traducir los ejes temáticos de mi evento, para clasificar propuestas | S | H-003-1 |
| H-004-3 | Como visitante, quiero ver la convocatoria con fechas, ejes y estado en mi idioma, para decidir si envío | M | H-004-1, H-004-2 |
| H-004-4 | Como organizador, quiero abrir, pausar y cerrar la convocatoria a mano, para controlar el envío | S | H-004-1 |

## 2. Punto de partida

Al 2026-11-09 el repositorio contiene solo la plantilla: no existen `composer.json`, `artisan` ni migraciones. Este plan parte de lo que entregan los planes 001 (organización, contexto, roles), 002 (esqueleto con página pública Inertia sin SSR) y 003 (`Event` con zona horaria e idiomas). En el cpanel de origen los ejes eran un catálogo oculto en el menú y la convocatoria no tenía fechas configurables (`docs/producto/cpanel-actual.md`).

`[PENDIENTE: fecha de resultados y fecha exacta del cierre de la convocatoria 2027 (3.ª semana de enero de 2027); los captura el organizador, no se siembran]`.

## 3. Decisiones

### DA-1 · Estado manual y estado efectivo — se recomienda **`status` manual más un estado efectivo calculado**

`status` (`draft`, `open`, `paused`, `closed`) lo cambia una persona. El estado que ve el público (`CallState`: `scheduled`, `open`, `paused`, `closed`) se **calcula en cada petición** a partir de `status` y de las fechas, sin depender del planificador. Así una convocatoria abre y cierra a su hora aunque falle un *worker*.

### DA-2 · Varias convocatorias por evento — se recomienda **permitirlas**

Un evento puede tener una convocatoria por tipo (sesión, panel, póster) o varias del mismo tipo con distinto formulario. `slug` es único por evento, no el tipo.

### DA-3 · Quién gestiona — se recomienda **administrador de organización gestiona; miembro solo consulta**

Permisos nuevos: `calls.view`, `calls.manage`, `tracks.manage`. El administrador los tiene todos; el miembro, solo `calls.view`. `[PENDIENTE: ¿el rol de coordinador de evento puede gestionar convocatorias en R0?]`

### DA-4 · Ejes con propuestas — se recomienda **archivar, no eliminar**

Un eje con propuestas no se borra: se archiva (`archived_at`), deja de ofrecerse en nuevos envíos y se conserva en las propuestas existentes.

## 4. Contrato

**Rutas web** (sin endpoints JSON nuevos; no se toca `docs/api/endpoints.md`):

| Ruta | Método | Quién |
|---|---|---|
| `/events/{event}/call-for-submissions/{call}` (`events.calls.show`) | GET | Público; `scopeBindings` acotado a la organización |
| Recursos de Filament `CallForSubmissionsResource` y `TrackResource` | — | Equipo con permiso |
| Acciones de Filament `OpenCall`, `PauseCall`, `CloseCall` | POST/PATCH con CSRF | `calls.manage` |

**Form Requests:** `StoreCallForSubmissionsRequest`, `UpdateCallForSubmissionsRequest`, `StoreTrackRequest`, `UpdateTrackRequest`, `ReorderTracksRequest`, `ChangeCallStatusRequest`.

**Actions:** `CreateCallForSubmissions`, `UpdateCallForSubmissions`, `ChangeCallStatus`, `CreateTrack`, `UpdateTrack`, `ReorderTracks`, `ArchiveTrack`. Clase de apoyo `ResolveCallState` (puro, probado con `Carbon::setTestNow`).

**Props de Inertia** (`Calls/Show`): título, descripción, tipo, idiomas, `state`, `opensAt`, `closesAt`, `resultsAt` (ISO 8601 en UTC) y `tracks[]` activos. Los tipos TypeScript se escriben a mano en `resources/js/types/`.

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** el evento recibe propuestas dentro de un periodo y las clasifica por eje. Módulo dueño: convocatoria. Ciclo de vida: de borrador a cerrada; se conserva como evidencia.
2. **Inspección:** no hay esquema previo (proyecto sin migraciones). Se revisó la tabla `temas` y el catálogo de ejes del cpanel como referencia funcional, sin reutilizarlos.
3. **Modelo:**
   - `call_for_submissions`: `id uuid`, `organization_id uuid NOT NULL`, `event_id uuid NOT NULL`, `slug`, `submission_type` (`session`, `panel`, `poster`, `workshop`, con `CHECK`), `title` y `description` (`jsonb` traducible), `locales` (`jsonb` con códigos de idioma, validado contra los del evento), `opens_at`, `closes_at`, `results_at` (`timestamptz`, esta última nula hasta definirla), `status` (con `CHECK`), `timestamps` y `SoftDeletes`. Ámbito: evento.
   - `tracks`: `id uuid`, `organization_id`, `event_id`, `name` y `description` (`jsonb` traducible), `position int`, `archived_at`, `timestamps`. Ámbito: evento.
4. **Integridad:** `organization_id NOT NULL` con clave foránea; clave foránea compuesta `(event_id, organization_id)` hacia `events` para impedir cruces entre organizaciones; `unique(organization_id, event_id, slug)`; `CHECK (closes_at > opens_at)`; índices `(organization_id, event_id, status)` y `(organization_id, event_id, position)`; `status` y `submission_type` como `enum` de PHP con `CHECK`.
5. **Cambio:** migraciones nuevas, sin datos previos. `down()` elimina las tablas. La migración la aplica el humano (acción 1 de `AGENTS.md`).
6. **Alternativa descartada:** guardar los ejes como lista dentro de `events` o de la convocatoria (`jsonb`). Se descartó porque las propuestas los referencian por clave foránea, se filtran y se reportan entre eventos, y un arreglo impide integridad y archivado.
7. **Preguntas de negocio:** `[PENDIENTE: fechas de cierre y de resultados de la convocatoria 2027]`; DA-3.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador de organización: crear, editar, abrir, pausar, cerrar y archivar convocatorias y ejes. Miembro: solo ver (`calls.view`). Visitante: ver la convocatoria no borrador. Soporte de plataforma: sin acceso salvo `withoutOrganizationScope()` auditado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `CallForSubmissionsPolicy` y `TrackPolicy`; `tests/Feature/Calls/` con una prueba por rol y acción (permitido y denegado) |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `CallForSubmissions` y `Track`, ambos con `BelongsToOrganization`; cubiertos por `ModelsAreScopedTest` y `TenantIsolationTest` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; las Policies usan el rol de organización. Cada consulta se acota con `forEvent($event)`. `event_assignments` llega en el plan 011 |
| ¿Datos personales nuevos? | No. Son textos que define la organización. Nada se añade a `docs/privacidad.md` |
| ¿Se suben archivos? | No |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos o notificaciones? | Ninguno en este plan. El recordatorio de cierre (`call.closing_reminder`) lo registra el plan 012 |
| ¿Qué se registra en la auditoría? | Alta, edición y cambios de estado de la convocatoria (quién, cuándo, valor anterior y nuevo); alta, orden y archivado de ejes (`spatie/laravel-activitylog`) |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Permisos `calls.view`, `calls.manage`, `tracks.manage` y su asignación a roles, con pruebas de rol; enum `SubmissionType`, `CallStatus`, `CallState` y `ResolveCallState` con pruebas de fronteras de fecha | S |
| F1 | `Track`: migración, modelo, Policy, recurso de Filament con orden, traducción y archivado (H-004-2) | S |
| F2 | `CallForSubmissions`: migración, modelo, Policy, recurso de Filament y validación de fechas e idiomas (H-004-1) | M |
| F3 | Estado manual: abrir, pausar y cerrar con auditoría (H-004-4) | S |
| F4 | Página pública `Calls/Show` con estados e i18n (es, en), accesible a ~375 px y ~1280 px (H-004-3) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-004-n: ...`) o declara su comprobación manual.

**H-004-1 · Crear la convocatoria**

- **CA-004-1:** *Dado* un administrador de la organización A y un evento de A con zona horaria `America/Mexico_City`, *cuando* crea una convocatoria de tipo `session` con apertura, cierre e idiomas válidos, *entonces* queda en estado `draft` con las fechas guardadas en UTC y se muestran en la zona del evento.
- **CA-004-2:** *Dado* un formulario de convocatoria, *cuando* el cierre es igual o anterior a la apertura, o los resultados son anteriores al cierre, *entonces* se rechaza con error de validación por campo; los resultados pueden quedar vacíos.
- **CA-004-3:** *Dado* un evento con idiomas `es` y `en`, *cuando* se indica un idioma que el evento no tiene o ninguno, *entonces* se rechaza.
- **CA-004-4:** *Dado* el tipo de propuesta, *cuando* el valor no pertenece a `session`, `panel`, `poster` o `workshop`, *entonces* se rechaza; y *cuando* se repite un `slug` en el mismo evento, *entonces* se rechaza, mientras que el mismo `slug` en otro evento o en otra organización se permite.
- **CA-004-5:** *Dado* un miembro sin `calls.manage`, *cuando* intenta crear o editar una convocatoria, *entonces* se deniega (403); *cuando* solo consulta, *entonces* se permite.
- **CA-004-6:** *Dado* el administrador de A, *cuando* intenta crear una convocatoria con el `event_id` de un evento de B, *entonces* se rechaza, y *cuando* abre por identificador una convocatoria de B, *entonces* la respuesta es 404.
- **CA-004-7:** *Dado* una convocatoria creada o editada, *cuando* se guarda, *entonces* queda un registro de auditoría con quién, cuándo y los valores anterior y nuevo.

**H-004-2 · Ejes temáticos**

- **CA-004-8:** *Dado* un evento, *cuando* el organizador crea ejes con nombre y descripción en es y en, y los reordena, *entonces* se guardan con su posición y se listan en ese orden.
- **CA-004-9:** *Dado* un eje, *cuando* falta el nombre en el idioma base del evento, *entonces* se rechaza; un idioma adicional sin traducir cae al idioma base al mostrarse.
- **CA-004-10:** *Dado* un eje que ya tiene propuestas (comprobado con el contrato de plan 006), *cuando* se intenta eliminar, *entonces* se rechaza y solo se permite archivarlo; un eje archivado no aparece en la convocatoria pública y se conserva en las propuestas existentes.
- **CA-004-11:** *Dado* un administrador de A, *cuando* lista o edita ejes por identificador de un evento o eje de B, *entonces* la respuesta es 404; un miembro sin `tracks.manage` recibe 403 al modificar.

**H-004-3 · Convocatoria pública**

- **CA-004-12:** *Dado* una convocatoria con `status = open`, *cuando* el momento actual es anterior a la apertura, entre apertura y cierre, o posterior al cierre, *entonces* el estado público es `scheduled`, `open` o `closed` respectivamente, con la apertura incluida y el cierre excluido.
- **CA-004-13:** *Dado* una convocatoria en `open` con fechas vigentes, *cuando* corre el reloj sin ejecutarse ningún comando programado, *entonces* el estado cambia a `closed` en la petición posterior al cierre (comprobado con `Carbon::setTestNow`).
- **CA-004-14:** *Dado* un visitante, *cuando* pide la convocatoria en `es` o `en`, *entonces* ve los textos en ese idioma, y si el idioma no está definido ve el idioma base; las fechas se muestran en la zona del evento con `Intl`.
- **CA-004-15:** *Dado* una convocatoria en `draft`, de otra organización o inexistente, *cuando* un visitante pide su URL, *entonces* la respuesta es 404 sin distinguir los casos.
- **CA-004-16:** *Dado* una convocatoria publicada, *cuando* se renderiza, *entonces* muestra solo los ejes no archivados en su orden, y las props no incluyen datos del equipo, identificadores internos de organización ni la fecha de resultados cuando es nula.
- **CA-004-17:** La página pública se usa con teclado, tiene `lang`, título y encabezados correctos, contraste AA y sin errores en consola.
  Manual: abrir `/events/{event}/call-for-submissions/{call}` a ~375 px y ~1280 px, recorrer con Tab, cambiar a `en`, y revisar la consola del navegador.

**H-004-4 · Estado manual**

- **CA-004-18:** *Dado* un administrador, *cuando* abre, pausa o cierra una convocatoria, *entonces* el cambio se hace solo por POST o PATCH con CSRF y Policy; una petición GET a esa acción responde 405 y no cambia nada.
- **CA-004-19:** *Dado* una convocatoria `open` en fechas vigentes, *cuando* se pausa, *entonces* el estado público es `paused` aunque las fechas estén vigentes, y al reabrirla vuelve a `open`.
- **CA-004-20:** *Dado* una convocatoria cuyo cierre ya pasó, *cuando* se intenta abrirla sin cambiar `closes_at`, *entonces* se rechaza con un mensaje que pide una nueva fecha de cierre.
- **CA-004-21:** *Dado* un cambio de estado, *cuando* se aplica, *entonces* queda auditado con quién, cuándo, estado anterior y nuevo; un miembro sin `calls.manage` recibe 403.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Convocatoria que abre o cierra a destiempo por zona horaria | Fechas en UTC, zona del evento en los bordes y pruebas de frontera con reloj controlado |
| Dependencia del planificador para cerrar | El estado efectivo se calcula en la petición (DA-1) |
| Un eje borrado rompe propuestas ya enviadas | Archivado en vez de borrado (DA-4) y clave foránea restrictiva |
| Los miembros no pueden preparar la convocatoria | DA-3 abierta con recomendación; el cambio de rol requiere especificación |
| Convocatoria visible sin formulario listo | La publicación sin formulario se bloquea al enviar (plan 006, `CALL_NOT_READY`) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
