# feat: decisiones y resultados (transiciones de estado y avisos al autor)

> Plan de la épica E-12 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 012: todos sus criterios son `CA-012-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Permitir que el equipo organizador decida el destino de cada propuesta con historial y reversa, y que el autor reciba el resultado por correo. En el cpanel de origen rechazar dejaba la propuesta sin estado, sin reversa y sin aviso (`docs/producto/analisis-cpanel-y-propuesta.md` §5.2); aquí cada transición es una Action auditada y el rechazo es reversible.

**Prioridad y fechas (R1):** este plan y el 011 deben estar **terminados antes del cierre de la convocatoria (jueves 21-ene-2027)**; la meta de R1 es el 18-dic-2026. H-012-1 va primero: la Action `MarkSubmissionUnderReview` la usa el plan 011. Si R0 se retrasa hasta el 4-dic-2026 (plan B), el orden de R1 no cambia y los planes 013 y 014 pueden terminar después del cierre, salvo H-014-1 (2FA).

**Dentro:** una Action por transición de `SubmissionStatus` (`docs/producto/glosario.md`), rechazo reversible, decisión directa desde `submitted` (eventos sin comité o propuestas importadas), reversa de aceptadas, en espera y rechazadas, decisión masiva sobre una selección filtrada con confirmación y reversa por lote, correos críticos de resultado (`submission.accepted`, `submission.rejected`, `submission.waitlisted`) registrados en el catálogo del plan 008, recordatorio de cierre, comentario opcional al autor.

**Fuera:** confirmación del ponente y requisitos (R2); evaluación (plan 011); personalización por evento de las plantillas (plan 013).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-012-1 | Como organizador, quiero aceptar, rechazar, poner en lista de espera y revertir una propuesta, para decidir con historial | M | H-006-1 |
| H-012-2 | Como organizador, quiero decidir sobre una selección filtrada, para ahorrar tiempo | M | H-012-1 |
| H-012-3 | Como autor, quiero recibir el resultado de mi propuesta, para saber qué sigue | M | H-012-1, H-008-2 |
| H-012-4 | Como autor, quiero un recordatorio antes del cierre, para corregir mi propuesta a tiempo | S | H-006-1; H-006-4 solo de forma condicional (si no existe, el recordatorio es informativo) |
| H-012-5 | Como organizador, quiero añadir un comentario opcional para el autor al decidir, para dar retroalimentación | S | H-012-1 |

## 2. Punto de partida

Estado esperado al iniciar: propuestas en `submitted` (R0); catálogo y plantillas de correo (plan 008); la fecha de resultados de la convocatoria (plan 004, `results_at`), que puede estar vacía. El flujo de estados del glosario es `draft → submitted → under_review → accepted → confirmed`, con `waitlisted`, `rejected` y `withdrawn`. En R0 no existe `draft` (RC-8). El plan 006 define el `CHECK` de la columna de estado con **todos** los estados desde R0, así que este plan no lo amplía. Se mide el estado real al abrir el plan.

## 3. Decisiones

### DA-1 · Quién puede revertir — se recomienda **un permiso específico `submissions.revert`**

La reversa de aceptadas, en espera y rechazadas la hace solo quien tenga ese permiso (administrador por defecto). Alternativa descartada: reversa libre para cualquier organizador, que facilita errores y abusos.

### DA-2 · Cuándo se envía el correo de resultado — se recomienda **envío diferido hasta «publicar resultados»**

Decidir no envía de inmediato: las decisiones quedan en estado interno y el correo sale cuando un usuario con `submissions.decide` publica los resultados de la convocatoria (acción con confirmación). Si la convocatoria ya tiene sus resultados publicados, las decisiones posteriores envían al decidir. Si la convocatoria **no tiene fecha de resultados**, nada se envía solo: solo la publicación manual dispara los correos. Evita avisar y luego revertir. `[PENDIENTE: fecha de resultados de la convocatoria 2027]`.

### DA-3 · Transiciones permitidas — se recomienda **la tabla del glosario con una entrada directa y reversas explícitas**

- `submitted → under_review | accepted | rejected | waitlisted` (la entrada directa sirve a eventos sin comité y a propuestas importadas del plan B).
- `under_review → accepted | waitlisted | rejected`.
- `waitlisted → accepted | rejected`.
- Reversas, con `submissions.revert`: `accepted | waitlisted | rejected → under_review`.
- Cualquiera hasta `confirmed` → `withdrawn` (`confirmed` llega en R2).
- Todo lo demás (por ejemplo `accepted → rejected` directa, o cualquier salida de `withdrawn`) lanza `INVALID_SUBMISSION_TRANSITION`.

### DA-4 · Reversa de una decisión ya publicada — se recomienda **exigir confirmación y no reenviar correo**

Revertir una decisión cuyo resultado ya se publicó exige confirmación explícita que advierte que el autor ya fue avisado; la reversa no envía correo. Si luego se decide distinto, la nueva decisión se publica con el mismo mecanismo. Alternativa descartada: bloquear la reversa tras publicar, que deja errores sin salida.

## 4. Contrato

- **Rutas web (Filament):** acciones de fila y masivas en `SubmissionResource` (panel de organizadores) por `POST`/`PATCH` con CSRF; sin rutas JSON ni entradas nuevas en `docs/api/endpoints.md`.
- **Invitados (plan 018):** `BulkDecideSubmissions` y `PublishResults` excluyen las propuestas con `origin = invited`; un invitado nunca recibe `submission.accepted`, porque no envió nada ni tiene resultados que publicar.
- **Actions** (una por transición, método `handle()`): `MarkSubmissionUnderReview` (la invoca `AssignReviewers` del plan 011), `AcceptSubmission`, `WaitlistSubmission`, `RejectSubmission`, `RevertSubmissionDecision`, `WithdrawSubmission`, `BulkDecideSubmissions`, `RevertDecisionBatch` y `PublishResults`.
- **Form Requests:** `DecideSubmissionRequest` (decisión y comentario opcional, longitud máxima), `BulkDecideRequest` (filtro o lista de identificadores, con máximo), `PublishResultsRequest`.
- **Correos en el catálogo (plan 008):** `submission.accepted`, `submission.rejected`, `submission.waitlisted` (críticos) y `call.closing_reminder` (no crítico), con predeterminadas es/en (y pt desde el plan 013) y variables en lista blanca: `author_name`, `event_name`, `submission_title`, `author_comment`, `request_link_url` (URL pública fija de «pedir mi enlace», **sin token**) y, solo en el recordatorio, `edit_hint` (texto ya traducido por el sistema, vacío si la edición no existe). **Ningún correo de resultado lleva enlace de acceso:** el autor pide su enlace de un solo uso en esa página (plan 006).
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* historial de decisiones, reversa por lote y su publicación; dueño: módulo de propuestas.
  2. *Modelo:* `submission_status_changes` (propuesta, estado anterior, estado nuevo, actor, `comment`, `author_comment`, `decision_batch_id` nullable, instante UTC; solo se añade); `decision_batches` (id, evento, actor, filtro aplicado, total, instante UTC); en `submissions`: `results_published_at` nullable y `author_comment` nullable; el estado sigue siendo `enum` con `CHECK` (definido en el plan 006). Ámbito: organización y evento.
  3. *Integridad:* claves foráneas con el mismo `organization_id` (con `unique(id, organization_id)` en las tablas referenciadas); sin borrado físico de historial.
  4. *Cambio:* tablas nuevas y columnas nulas; sin transformación de datos.
  5. *Alternativa descartada:* solo `spatie/laravel-activitylog` para el historial; el historial de estados es dato de negocio consultable (ADR-0005 DA-2).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` y `organizer` con `submissions.decide`: aceptar, rechazar, lista de espera, decidir en masa y publicar resultados. `submissions.revert`: revertir (por defecto `org_admin`). `reviewer` y autores: ninguno |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` (métodos `decide`, `revert`, `publishResults`); `tests/Feature/Submissions/` con permitido y denegado por rol |
| ¿Modelos nuevos con datos de organización? | `SubmissionStatusChange` y `DecisionBatch` con `BelongsToOrganization`; cubiertos por las guardianas |
| ¿El acceso cruza eventos o depende de `event_assignments`? | La decisión se acota al evento de la propuesta; un coordinador asignado a un solo evento no decide en otro |
| ¿Datos personales nuevos? | Comentario para el autor (texto libre); se añade al inventario de `docs/privacidad.md`; se anonimiza con la persona (plan 014) |
| ¿Se suben archivos? | No |
| ¿Dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos? | Sí: resultados críticos y recordatorio, por cola, con idempotencia (una sola vez por propuesta y resultado) |
| ¿Qué se registra en la auditoría? | Cada transición con actor, motivo y comentario; decisiones masivas con el filtro y el total; publicación de resultados; reversas |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos aprobada; tabla de transiciones con pruebas unitarias | S |
| F1 | Actions por transición, historial y auditoría (H-012-1, H-012-5) | M |
| F2 | Decisión masiva, reversa por lote y publicación de resultados (H-012-2) | M |
| F3 | Correos de resultado y recordatorio en el catálogo (H-012-3, H-012-4) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-012-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-012-1 · Transiciones con historial

- **CA-012-1:** *Dado* una propuesta `under_review`, *cuando* un organizador con `submissions.decide` la acepta, *entonces* pasa a `accepted`, se registra el cambio con actor e instante UTC y queda auditado.
- **CA-012-2:** *Dado* una propuesta `submitted` en un evento sin comité, *cuando* un organizador con `submissions.decide` la acepta, la rechaza o la pone en espera, *entonces* la transición directa se aplica con historial.
- **CA-012-3:** *Dado* una propuesta `accepted`, `waitlisted` o `rejected`, *cuando* un usuario con `submissions.revert` la revierte, *entonces* vuelve a `under_review` conservando el historial; sin el permiso se deniega.
- **CA-012-4:** *Dado* una transición no permitida (por ejemplo `accepted → rejected` directa o cualquier salida de `withdrawn`), *cuando* se intenta, *entonces* se lanza `INVALID_SUBMISSION_TRANSITION` y el estado no cambia.
- **CA-012-5:** *Dado* una propuesta de la organización B, *cuando* un organizador de A intenta decidirla por su identificador, *entonces* la respuesta es 404.
- **CA-012-6:** *Dado* dos organizadores que deciden la misma propuesta al mismo tiempo, *cuando* ambos confirman, *entonces* solo una transición se aplica y la otra recibe un error de conflicto (control de concurrencia).
- **CA-012-7:** *Dado* una petición `GET` a una URL de decisión, *cuando* se envía, *entonces* no cambia ningún estado (solo `POST` o `PATCH` con CSRF).
- **CA-012-8:** *Dado* una decisión cuyo resultado ya se publicó, *cuando* se revierte, *entonces* se exige confirmación explícita que advierte que el autor ya fue avisado, la reversa se aplica y no se envía ningún correo.

### H-012-2 · Decisión masiva

- **CA-012-9:** *Dado* una selección filtrada de N propuestas elegibles, *cuando* el organizador decide en masa y confirma, *entonces* todas pasan al estado elegido en una transacción, comparten un `decision_batch_id` y se audita el filtro y el total.
- **CA-012-10:** *Dado* una selección con una propuesta no elegible, *cuando* se confirma, *entonces* toda la operación se rechaza e informa cuál falló (sin cambios parciales).
- **CA-012-11:** *Dado* una selección mayor que el máximo permitido por operación, *cuando* se intenta, *entonces* se rechaza con error de validación.
- **CA-012-12:** *Dado* una decisión masiva ya aplicada, *cuando* un usuario con `submissions.revert` revierte el lote, *entonces* las propuestas del lote vuelven a su estado anterior; sin el permiso se deniega.
- **CA-012-13:** *Dado* un lote en el que una propuesta cambió de estado después de la decisión masiva, *cuando* se revierte el lote, *entonces* esa propuesta no se toca y el resumen la lista como omitida con su motivo.

### H-012-3 · Correos de resultado

- **CA-012-14:** *Dado* una propuesta `accepted` con `results_published_at` vacío, *cuando* un usuario con `submissions.decide` publica los resultados de su convocatoria, *entonces* se fija `results_published_at`, se encola `submission.accepted` al autor con el comentario opcional, y el correo no contiene ningún token: enlaza a «pedir mi enlace».
- **CA-012-15:** *Dado* resultados ya publicados, *cuando* se vuelve a publicar o se reintenta el trabajo, *entonces* no se envía un segundo correo al mismo autor por el mismo resultado (idempotencia).
- **CA-012-16:** *Dado* una decisión sin publicar, *cuando* el autor consulta con su enlace de acceso, *entonces* ve «en evaluación», no el resultado interno.
- **CA-012-17:** *Dado* el catálogo del plan 008, *cuando* corren las guardianas, *entonces* `submission.accepted`, `submission.rejected` y `submission.waitlisted` están registradas como críticas con predeterminada en es y en, no se pueden desactivar y ninguna declara la variable `access_link`.
- **CA-012-18:** *Dado* un fallo del proveedor de correo, *cuando* falla el envío, *entonces* el trabajo se reintenta, el fallo es visible en los registros y la decisión no se revierte.
- **CA-012-19:** *Dado* un usuario sin `submissions.decide`, *cuando* intenta publicar resultados, *entonces* la respuesta es 403 y no se envía ningún correo ni cambia ningún estado.
- **CA-012-20:** *Dado* una convocatoria sin fecha de resultados, *cuando* se decide una propuesta, *entonces* no se envía ningún correo hasta que alguien publique los resultados manualmente.
- **CA-012-21:** *Dado* una convocatoria con resultados ya publicados, *cuando* se decide una propuesta nueva, *entonces* el correo de resultado se encola al decidir, una sola vez.
- **CA-012-22:** *Dado* una convocatoria de la organización B, *cuando* un organizador de A intenta publicar sus resultados por su identificador, *entonces* la respuesta es 404.

### H-012-4 · Recordatorio de cierre

- **CA-012-23:** *Dado* una convocatoria que cierra en N días configurables y propuestas `submitted`, *cuando* corre el planificador (con el contexto de cada organización), *entonces* cada autor recibe `call.closing_reminder` una sola vez.
- **CA-012-24:** *Dado* una propuesta retirada o una convocatoria ya cerrada, *cuando* corre el planificador, *entonces* no se envía recordatorio.
- **CA-012-25:** *Dado* el cierre en la zona horaria del evento, *cuando* se calcula «faltan N días», *entonces* se usa la zona del evento y el instante UTC almacenado.
- **CA-012-26:** *Dado* que la edición por el autor (H-006-4) no existe en el entorno, *cuando* se envía el recordatorio, *entonces* es informativo: `edit_hint` queda vacío y el texto no invita a editar; con la edición disponible, `edit_hint` lleva la invitación y la URL de «pedir mi enlace».

### H-012-5 · Comentario al autor

- **CA-012-27:** *Dado* un organizador que decide, *cuando* añade un comentario opcional, *entonces* se guarda con la decisión y el correo lo incluye solo si existe.
- **CA-012-28:** *Dado* un comentario con etiquetas HTML o scripts, *cuando* se guarda y se envía, *entonces* se sanitiza y no se ejecuta ni se interpreta como HTML ni como Markdown con enlaces.
- **CA-012-29:** *Dado* un comentario que excede la longitud máxima, *cuando* se guarda, *entonces* se rechaza con error de validación.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Avisar un resultado equivocado | Envío diferido hasta publicar y confirmación explícita (DA-2, CA-012-16, 20) |
| Decisión masiva con alcance equivocado | Transacción única, confirmación y reversa por lote (CA-012-9 a 13) |
| Correos duplicados por reintentos | Idempotencia por propuesta y resultado (CA-012-15) |
| Enlace de acceso caducado en un correo de resultado | El correo no lleva token y enlaza a «pedir mi enlace» (CA-012-14) |
| Comentarios con contenido peligroso | Sanitización en el servidor (CA-012-28) |
| R1 comprimido por un R0 tardío | Prioridad declarada en §1: H-012-1 primero; este plan antes del 21-ene |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
