# feat: decisiones y resultados (transiciones de estado y avisos al autor)

> Plan de la épica E-12 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 012: todos sus criterios son `CA-012-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Permitir que el equipo organizador decida el destino de cada propuesta con historial y reversa, y que el autor reciba el resultado por correo. En el cpanel de origen rechazar dejaba la propuesta sin estado, sin reversa y sin aviso (`docs/producto/analisis-cpanel-y-propuesta.md` §5.2); aquí cada transición es una Action auditada y el rechazo es reversible.

**Dentro:** una Action por transición de `SubmissionStatus` (`docs/producto/glosario.md`), rechazo reversible, decisión masiva sobre una selección filtrada con confirmación y reversa, correos críticos de resultado (`submission.accepted`, `submission.rejected`, `submission.waitlisted`) registrados en el catálogo del plan 008, recordatorio de cierre, comentario opcional al autor.

**Fuera:** confirmación del ponente y requisitos (R2); evaluación (plan 011); personalización por evento de las plantillas (plan 013).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-012-1 | Como organizador, quiero aceptar, rechazar, poner en lista de espera y revertir una propuesta, para decidir con historial | M | H-011-5 |
| H-012-2 | Como organizador, quiero decidir sobre una selección filtrada, para ahorrar tiempo | M | H-012-1 |
| H-012-3 | Como autor, quiero recibir el resultado de mi propuesta, para saber qué sigue | M | H-012-1, H-008-2 |
| H-012-4 | Como autor, quiero un recordatorio antes del cierre, para corregir mi propuesta a tiempo | S | H-006-4 |
| H-012-5 | Como organizador, quiero añadir un comentario opcional para el autor al decidir, para dar retroalimentación | S | H-012-1 |

## 2. Punto de partida

Estado esperado al iniciar: propuestas en `submitted` (R0), resultados ponderados (plan 011), catálogo y plantillas de correo (plan 008). El flujo de estados del glosario: `draft → submitted → under_review → accepted → confirmed`, con `waitlisted`, `rejected` y `withdrawn`. En R0 no existe `draft` (RC-8). Se mide el estado real al abrir el plan.

## 3. Decisiones

### DA-1 · Quién puede revertir — se recomienda **un permiso específico `submissions.revert`**

El rechazo y la lista de espera son reversibles solo por quien tenga ese permiso (administrador por defecto). Alternativa descartada: reversa libre para cualquier organizador, que facilita errores y abusos.

### DA-2 · Cuándo se envía el correo de resultado — se recomienda **envío diferido hasta la fecha de resultados de la convocatoria**

Decidir no envía de inmediato: las decisiones quedan en estado interno y el correo sale al «publicar resultados» (acción masiva con confirmación) o, si la convocatoria ya pasó su fecha de resultados, al decidir. Evita avisar y luego revertir. `[PENDIENTE: fecha de resultados de la convocatoria 2027]`.

### DA-3 · Transiciones permitidas — se recomienda **la tabla del glosario, sin atajos**

`submitted → under_review → accepted|waitlisted|rejected`; `waitlisted → accepted`; cualquiera hasta `confirmed` → `withdrawn`; `rejected → under_review` (reversa). Una transición inválida lanza una excepción de dominio con código estable (`INVALID_SUBMISSION_TRANSITION`).

## 4. Contrato

- **Rutas web (Filament):** acciones de fila y masivas en `SubmissionResource` (panel de organizadores) por `POST`/`PATCH` con CSRF; sin rutas JSON ni entradas nuevas en `docs/api/endpoints.md`.
- **Actions** (una por transición, método `handle()`): `MarkSubmissionUnderReview`, `AcceptSubmission`, `WaitlistSubmission`, `RejectSubmission`, `RevertSubmissionDecision`, `WithdrawSubmission`, `BulkDecideSubmissions` y `PublishResults`.
- **Form Requests:** `DecideSubmissionRequest` (decisión y comentario opcional, longitud máxima), `BulkDecideRequest` (filtro o lista de identificadores, con máximo), `PublishResultsRequest`.
- **Correos nuevos en el catálogo (plan 008):** `submission.accepted`, `submission.rejected`, `submission.waitlisted` (críticos) y `call.closing_reminder` (no crítico), con predeterminadas es/en y variables en lista blanca (`author_name`, `event_name`, `submission_title`, `author_comment`, `access_link`).
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* historial de decisiones y su publicación; dueño: módulo de propuestas.
  2. *Modelo:* `submission_status_changes` (propuesta, estado anterior, estado nuevo, actor, `comment`, `author_comment`, instante UTC; solo se añade); en `submissions`: `results_published_at` nullable y `author_comment` nullable; el estado sigue siendo `enum` con `CHECK`. Ámbito: organización y evento.
  3. *Integridad:* claves foráneas con el mismo `organization_id`; `CHECK` de estados válidos; sin borrado físico de historial.
  4. *Cambio:* tabla nueva y columnas nulas; sin transformación de datos.
  5. *Alternativa descartada:* solo `spatie/laravel-activitylog` para el historial; el historial de estados es dato de negocio consultable (ADR-0005 DA-2).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Organizadores con permiso `submissions.decide`: aceptar, rechazar, lista de espera, decidir en masa y publicar resultados. `submissions.revert`: revertir. Evaluadores y autores: ninguno |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` (métodos `decide`, `revert`, `publishResults`); `tests/Feature/Submissions/` con permitido y denegado por rol |
| ¿Modelos nuevos con datos de organización? | `SubmissionStatusChange` con `BelongsToOrganization`; cubierto por las guardianas |
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
| F2 | Decisión masiva y publicación de resultados (H-012-2) | M |
| F3 | Correos de resultado y recordatorio en el catálogo (H-012-3, H-012-4) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-012-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-012-1 · Transiciones con historial

- **CA-012-1:** *Dado* una propuesta `under_review`, *cuando* un organizador con permiso la acepta, *entonces* pasa a `accepted`, se registra el cambio con actor e instante UTC y queda auditado.
- **CA-012-2:** *Dado* una propuesta `rejected`, *cuando* un usuario con `submissions.revert` la revierte, *entonces* vuelve a `under_review` conservando el historial; sin el permiso se deniega.
- **CA-012-3:** *Dado* una transición no permitida (por ejemplo `accepted → rejected` directa o desde `withdrawn`), *cuando* se intenta, *entonces* se lanza `INVALID_SUBMISSION_TRANSITION` y el estado no cambia.
- **CA-012-4:** *Dado* una propuesta de la organización B, *cuando* un organizador de A intenta decidirla por su identificador, *entonces* la respuesta es 404.
- **CA-012-5:** *Dado* dos organizadores que deciden la misma propuesta al mismo tiempo, *cuando* ambos confirman, *entonces* solo una transición se aplica y la otra recibe un error de conflicto (control de concurrencia).
- **CA-012-6:** *Dado* una petición `GET` a una URL de decisión, *cuando* se envía, *entonces* no cambia ningún estado (solo `POST` o `PATCH` con CSRF).

### H-012-2 · Decisión masiva

- **CA-012-7:** *Dado* una selección filtrada de N propuestas `under_review`, *cuando* el organizador decide en masa y confirma, *entonces* todas pasan al estado elegido en una transacción y se audita el filtro y el total.
- **CA-012-8:** *Dado* una selección con una propuesta no elegible, *cuando* se confirma, *entonces* toda la operación se rechaza e informa cuál falló (sin cambios parciales).
- **CA-012-9:** *Dado* una selección mayor que el máximo permitido por operación, *cuando* se intenta, *entonces* se rechaza con error de validación.
- **CA-012-10:** *Dado* una decisión masiva ya aplicada, *cuando* el organizador la revierte desde el resumen, *entonces* las propuestas vuelven a su estado anterior, con permiso `submissions.revert`.

### H-012-3 · Correos de resultado

- **CA-012-11:** *Dado* una propuesta aceptada y resultados publicados, *cuando* se publica, *entonces* se encola `submission.accepted` al autor, con el enlace de acceso y el comentario opcional.
- **CA-012-12:** *Dado* resultados ya publicados, *cuando* se vuelve a publicar o reintenta, *entonces* no se envía un segundo correo al mismo autor por el mismo resultado (idempotencia).
- **CA-012-13:** *Dado* una decisión sin publicar, *cuando* el autor consulta con su enlace de acceso, *entonces* ve «en evaluación», no el resultado interno.
- **CA-012-14:** *Dado* el catálogo del plan 008, *cuando* corren las guardianas, *entonces* `submission.accepted`, `submission.rejected` y `submission.waitlisted` están registradas como críticas con predeterminada en es y en, y no se pueden desactivar.
- **CA-012-15:** *Dado* un fallo del proveedor de correo, *cuando* falla el envío, *entonces* el trabajo se reintenta, el fallo es visible en los registros y la decisión no se revierte.

### H-012-4 · Recordatorio de cierre

- **CA-012-16:** *Dado* una convocatoria que cierra en N días configurables y propuestas `submitted` editables, *cuando* corre el planificador, *entonces* cada autor recibe `call.closing_reminder` una sola vez.
- **CA-012-17:** *Dado* una propuesta retirada o una convocatoria ya cerrada, *cuando* corre el planificador, *entonces* no se envía recordatorio.
- **CA-012-18:** *Dado* el cierre en la zona horaria del evento, *cuando* se calcula «faltan N días», *entonces* se usa la zona del evento y el instante UTC almacenado.

### H-012-5 · Comentario al autor

- **CA-012-19:** *Dado* un organizador que decide, *cuando* añade un comentario opcional, *entonces* se guarda con la decisión y el correo lo incluye solo si existe.
- **CA-012-20:** *Dado* un comentario con etiquetas HTML o scripts, *cuando* se guarda y se envía, *entonces* se sanitiza y no se ejecuta ni se interpreta como HTML.
- **CA-012-21:** *Dado* un comentario que excede la longitud máxima, *cuando* se guarda, *entonces* se rechaza con error de validación.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Avisar un resultado equivocado | Envío diferido hasta publicar y confirmación explícita (DA-2, CA-012-13) |
| Decisión masiva con alcance equivocado | Transacción única, confirmación y reversa (CA-012-7 a 10) |
| Correos duplicados por reintentos | Idempotencia por propuesta y resultado (CA-012-12) |
| Comentarios con contenido peligroso | Sanitización en el servidor (CA-012-20) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
