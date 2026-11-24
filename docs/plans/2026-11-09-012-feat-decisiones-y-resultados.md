# feat: decisiones y resultados (transiciones de estado y avisos al autor)

> Plan de la épica E-12 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 012: todos sus criterios son `CA-012-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Aprobado (Antonio, 2026-11-23: especificacion, DA-1 a DA-4 como se recomiendan y propuesta de datos de §4; F0 y F1 construidas. Antonio, 2026-11-24: alcance ampliado a F2 y F3, es decir H-012-2, H-012-3 y H-012-4; la fecha de resultados de la convocatoria 2027 sigue pendiente y no bloquea el codigo). F2 y F3 construidas el 2026-11-24 en la rama `feat/plan-012-decision-masiva` (pendiente de integracion a `staging`))
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
- **Programa (plan 021):** `AcceptSubmission` (y `BulkDecideSubmissions` al aceptar en masa) llama a `PromoteSubmissionToProgram` en la misma transacción para crear ponentes y actividad; `RevertSubmissionDecision` (desde `accepted`) y `WithdrawSubmission` (de una aceptada) llaman a `HideProgramOfSubmission`, y la pantalla avisa de cuántos ponentes y qué actividad se ocultaron. Ambas Actions las entrega el plan 021 (fase F5); hasta entonces este plan las invoca solo en pruebas. Sin correo.
- **Invitados:** el plan 018 quedó reemplazado por el plan 021: los invitados se dan de alta en Ponentes y no son propuestas, así que este plan ya no necesita excluir propuestas con `origin = invited` (se retiró esa nota; ningún criterio CA-012-n dependía de ella). Si en algún momento existieran propuestas con `origin = invited`, deben quedar fuera de las decisiones masivas y de los resultados.
- **Actions** (una por transición, método `handle()`): `MarkSubmissionUnderReview` (la invoca `AssignReviewers` del plan 011), `AcceptSubmission`, `WaitlistSubmission`, `RejectSubmission`, `RevertSubmissionDecision`, `WithdrawSubmission`, `BulkDecideSubmissions`, `RevertDecisionBatch` y `PublishResults`.
- **Contrato de `MarkSubmissionUnderReview` (fijado en F0, para el plan 011):** `App\Actions\Submissions\MarkSubmissionUnderReview::handle(?User $actor, Submission $submission): bool`. Solo `submitted → under_review`, con historial y auditoría. Idempotente: si ya está `under_review` no cambia el estado ni duplica el historial y devuelve `false`; devuelve `true` solo si cambió. Lee el estado actual de la base (una instancia vieja no provoca conflicto). Cualquier otro estado (decidida o retirada) lanza `InvalidSubmissionTransition`; volver a `under_review` desde una decisión es una reversa y exige `RevertSubmissionDecision`. **No autoriza** (lo hace la Action que la llama, p. ej. `AssignReviewers` con el permiso de asignar): solo invocarla desde otra Action. `$actor` es nulo si lo hace un trabajo programado. Se ejecuta en la organización de la propuesta (con o sin contexto previo) y participa de la transacción de quien la llama. Se inyecta por constructor, nunca con `new`.
- **Firmas de F1:** `AcceptSubmission`, `WaitlistSubmission` y `RejectSubmission`: `handle(User $actor, Submission $submission, ?string $authorComment = null): Submission`; `RevertSubmissionDecision::handle(User $actor, Submission $submission, ?string $reason = null, bool $confirmedPublished = false): ProgramReversal`. `$submission->status` es el estado que vio quien decide (control de concurrencia: `SubmissionStatusConflict`, código `SUBMISSION_STATUS_CONFLICT`). Comentario máximo: 1000 caracteres, texto plano (se descartan las etiquetas HTML).
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
| F0 | `d5a3e6e` | 2026-11-23 | Propuesta de datos de §4 aprobada (Antonio, 2026-11-23). Tabla de transiciones (`SubmissionTransitions`, DA-3) con `InvalidSubmissionTransition`: `tests/Unit/Support/SubmissionTransitionsTest.php` (CA-012-1 a 4) pasa; `LangParityTest` pasa; Pint 771 archivos PASS; Larastan sin errores; `composer check:criteria` 0 errores. Contrato de `MarkSubmissionUnderReview` fijado en §4. |
| F1 | `dba14b3` (datos), `c0f8207` (Actions), `3886b70` (interfaz) | 2026-11-23 | H-012-1 y H-012-5. Cubre CA-012-1 a 8 y CA-012-27 a 29 (CA-012-7 con una prueba de que el `GET` del detalle no cambia el estado; CA-012-6 con dos instancias viejas en la Action). Pruebas nuevas: `SubmissionDecisionsTest` (42), `SubmissionDecisionPanelTest` (10), `SubmissionStatusChangeSchemaTest`. En `testing_012`: `tests/Feature/Submissions`, `Auth`, `Program` y `LangParityTest` = 918 pasan y 9 fallan, todos ajenos (exportación CA-009-11 a 19: falta `maatwebsite/excel` en el `vendor/` del árbol principal); `Tenancy` 205 pasan; `Arch` 41 pasan; el resto de `Feature` (Calls, Catalog, Events, Forms, Http, Landings, Mail, Notifications, Organizations, Panel, Platform, Privacy, Team) y `Unit` = 1115 pasan y 2 fallan (`PrivacyNoticePanelTest` CA-003-11 y CA-003-14, estado del editor de texto enriquecido; no tocan nada de este plan, **no comprobado contra `staging` limpio**). Pint PASS; Larastan sin errores; `composer check:criteria` 0 errores. **No ejecutado:** `npm run typecheck/lint/test/build` (sin cambios de React), verificación en navegador a 375 y 1280 px del detalle con los botones y el historial, y aplicar las migraciones fuera de `testing_012`. |
| Desviaciones F1 | | | (1) Las tablas `decision_batches` (sin modelo, llega en F2) y la columna `results_published_at` se crean en F1 porque el historial referencia el lote y CA-012-8 necesita saber si el resultado ya se publicó. (2) No hay `DecideSubmissionRequest`: no existe ruta HTTP (las acciones son de Filament); la validación (longitud máxima, texto plano) vive en `AuthorComment` y la Action lanza `ValidationException`. (3) `WithdrawSubmission` (retiro del autor, plan 006) no cambia: el retiro por el equipo organizador y su llamada a `HideProgramOfSubmission` quedan como deuda DT-19. (4) El plan decía que `PromoteSubmissionToProgram` y `HideProgramOfSubmission` solo se invocarían en pruebas hasta el plan 021 F5; como ya existen, `AcceptSubmission` y `RevertSubmissionDecision` las llaman en la misma transacción. (5) La reversa limpia `results_published_at` y el comentario para el autor (el resultado anterior ya no vale; F3 decide el reenvío al decidir de nuevo). (6) Las acciones no aparecen para propuestas `origin = invited`. (7) La restricción «un coordinador asignado a un solo evento no decide en otro» (§5) no se aplica: no existe ese rol en `RolePermissions` y ninguna Policy de propuestas filtra por `event_assignments`. (8) Se añadió una fila al inventario de `docs/privacidad.md` y no se actualizó la tabla de permisos del plan 001. |
| F2 | `ef5d119` (con F3: resultados) | 2026-11-24 | H-012-2 y la publicacion de H-012-3. Cubre CA-012-9 a 13 (`BulkDecisionTest`, `BulkDecisionPanelTest`, `DecisionBatchSchemaTest`), CA-012-14 a 22 (`PublishResultsTest`) y CA-012-8 para lotes. `BulkDecideSubmissions`, `RevertDecisionBatch`, `PublishResults`, modelo `DecisionBatch` con su fabrica, columna `call_for_submissions.results_published_at` (migracion `2026_11_27_100000`, aplicada solo en `testing_012j`), acciones de lista en `ManageEventSubmissions` (aceptar, lista de espera y rechazar lo marcado, decidir sobre el filtro, revertir lote, publicar resultados) y la Policy `SubmissionPolicy` con `publishResults`, `revertBatch`, `decideMany` y `revertMany` (sin permisos nuevos: `submissions.decide` y `submissions.revert`). |
| F3 | `ef5d119` (correos de resultado), `47430e8` (recordatorio) | 2026-11-24 | H-012-3 y H-012-4. Cubre CA-012-14 a 17 y 27, `NotificationCatalogTest` (CA-012-17) y CA-012-23 a 26 (`ClosingReminderTest`). Catalogo: `submission.accepted`, `submission.rejected`, `submission.waitlisted` (criticas) y `call.closing_reminder` (no critica), con plantillas es/en; `PublishSubmissionResult` (envio solo por `SendNotification`, idempotente por propuesta y estado); `SendClosingReminders` y el comando `calls:send-closing-reminders` (diario 09:00). **Validaciones (rama `feat/plan-012-decision-masiva`, base `testing_012j`):** `tests/Arch tests/Feature tests/Unit` = 2693 pasan y 11 fallan, todos ajenos y conocidos en `staging` (9 de exportacion CA-009-11 a 19 por falta de `maatwebsite/excel` en el `vendor/` compartido y 2 de `PrivacyNoticePanelTest` CA-003-11 y CA-003-14); Pint PASS (949 archivos); Larastan sin errores; `composer check:criteria` 0 errores (123 criterios; los 12 avisos son del plan 002). **No ejecutado:** `npm run typecheck/lint/test/build` (sin cambios de React), verificacion en navegador a 375 y 1280 px de la lista con las acciones masivas y sus confirmaciones, un envio real de correo (solo `Queue::fake()` y el mailer simulado), el ensayo de carga del tope de 200 por lote y la aplicacion de la migracion fuera de `testing_012j`. |
| Desviaciones F2 y F3 | | | (1) **Todo o nada y omitidas (CA-012-10):** la Action rechaza el lote entero si alguna propuesta no es elegible (como pide el criterio); la pantalla, para cumplir «nunca en silencio», parte antes la seleccion en elegibles y no elegibles, muestra en la confirmacion cuantas se afectaran por estado y cuantas se omitiran, y solo manda las elegibles; las omitidas se listan en el aviso. (2) **Reversa de lote:** vuelve cada propuesta a `under_review`, no al estado anterior exacto, porque la tabla de transiciones (DA-3) solo permite ese destino (DT-44). Repetir la reversa de un lote ya revertido omite todas (idempotente). (3) **Fecha de resultados:** `results_at` es informativa; no hay publicacion automatica al llegar la fecha, solo la manual (DT-42). (4) **Datos:** se anade `call_for_submissions.results_published_at` (la propuesta de §4 solo traia la de `submissions`); sin ella no se puede saber que una convocatoria ya publico (CA-012-21); instante UTC, nula, sin transformar datos. Quien publica queda en la auditoria `call_results_published`. (5) **Variables:** el recordatorio usa `author_name`, `event_name`, `closing_date`, `edit_hint` y `request_link_url` (anade `closing_date`, sin `submission_title` ni `author_comment`: un autor con varias propuestas recibe un solo aviso). En los correos de resultado `author_comment` ya viaja con su rotulo traducido («Comentario del equipo organizador: ...») y vacio si no hay comentario; el texto sigue siendo plano y escapado por `RenderMailTemplate`. (6) **Recordatorio:** «faltan N dias» son dias de calendario en la zona del evento con «o menos» (3 por defecto, `submissions.closing_reminder_days`) para no perder el aviso si un dia no corrio la tarea; `edit_hint` solo invita si `PrepareAuthorEdit` devuelve la pantalla de edicion (convocatoria abierta, formulario publicado). (7) **Tope:** 200 propuestas por operacion (`BulkDecideRequest::MAX_SUBMISSIONS`), sin ensayo de carga. (8) **Ranking del plan 011:** no se integra «decidir sobre las completas por posicion» (DT-44). (9) **Prueba primero:** las pruebas de F2 y F3 se escribieron junto con el codigo y se vieron fallar solo en el ciclo de ajuste, no antes de escribir el codigo; los criterios quedan cubiertos pero el orden rojo-verde no se registro. (10) **Fallo de encolado:** si `SendNotification` guarda el registro y el encolado falla despues, la fila queda `queued` sin trabajo (DT-43); un fallo de aviso por propuesta se cuenta, se registra y deja la propuesta sin publicar para reintentarla. |
