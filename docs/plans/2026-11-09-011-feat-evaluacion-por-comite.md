# feat: evaluación por comité (rúbrica, asignación, panel del evaluador y resultados)

> Plan de la épica E-11 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 011: todos sus criterios son `CA-011-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** En curso (aprobado: Antonio, 2026-11-23: ampliacion de H-011-2 y H-011-3, DA-6 a DA-8 como se recomiendan, avance por evaluador con `reviews.view_progress` y propuesta de datos de `review_assignment_scopes` y del estado `cancelled`; F0 a F2 construidas, rubrica y asignaciones; F3a a F6 pendientes)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna (depende de RC-2: la 2FA obligatoria, H-014-1, debe estar activa antes de dar acceso a evaluadores externos; ver plan 014)

## 1. Objetivo y alcance

Reemplazar el motor de evaluación del cpanel de origen (rúbrica 1 a 5, evaluadores por tema, avance por evaluador; `docs/producto/analisis-cpanel-y-propuesta.md` §5.5) por uno con control de acceso real: rúbricas versionadas por evento y tipo de propuesta, asignación de evaluadores, invitación por correo, panel propio del evaluador, conflicto de interés, revisión ciega, guardado transaccional con historial y resultados ponderados. Lo usa el equipo organizador para decidir y el comité para evaluar, antes del cierre de la convocatoria.

**Prioridad y fechas (R1):** este plan y el 012 deben estar **terminados antes del cierre de la convocatoria (jueves 21-ene-2027)**; la meta de R1 es el 18-dic-2026. Los planes 013 y 014 pueden terminar después del cierre, **salvo H-014-1 (2FA)**, que bloquea la invitación de evaluadores externos (H-011-3 y H-011-8 dependen de ella). Si el lanzamiento de R0 se retrasa hasta el objetivo del plan B (4-dic-2026), R1 conserva el orden: primero H-011-7, H-012-1 y H-014-1; después H-011-1 a H-011-6 y H-011-8; el modo ciego (H-011-4) es lo primero que se recorta si no cabe, nunca las Policies.

**Dentro:** `event_assignments` con roles de evento `reviewer` y `coordinator` (ADR-0003 DA-1); rúbrica con criterios, escala, peso y umbral de discrepancia; asignación manual de evaluadores a ejes y a propuestas con mínimo de evaluadores; invitación del evaluador; panel Filament del evaluador; declaración de conflicto de interés y modo ciego; reevaluación con historial; resultados (promedio ponderado, desviación, discrepancias); avance por evaluador y cierre del periodo.

**Fuera:** aceptar, rechazar y avisar (plan 012); asignación automática balanceada (pospuesta a R2); 2FA y RLS (plan 014); portal del ponente y rol `speaker` de evento (R2); eliminación de metadatos internos de PDF y PPTX (riesgo declarado en §8).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-011-1 | Como organizador, quiero crear una rúbrica por evento y tipo de propuesta con criterios, escala, peso y umbral de discrepancia, para evaluar con mis reglas | M | H-006-1 |
| H-011-2 | Como integrante del equipo con permiso de asignación, quiero asignar evaluadores a una propuesta, a un eje o a una convocatoria completa, con un mínimo por propuesta y viendo la cobertura y la carga de cada evaluador, para repartir el trabajo | L | H-011-7, H-012-1 (Action `MarkSubmissionUnderReview`) |
| H-011-3 | Como evaluador, quiero un panel propio cuya página de inicio sea un tablero con el resumen y la lista de mis propuestas asignadas, y calificar con guardado transaccional, para evaluar sin ver lo ajeno | M | H-011-1, H-011-2, H-014-1 |
| H-011-4 | Como evaluador, quiero declarar conflicto de interés y, si el evento lo pide, evaluar en modo ciego, para una evaluación justa | M | H-011-3, H-005-1 (atributo `identifies_author`) |
| H-011-5 | Como organizador, quiero resultados con promedio ponderado, desviación y discrepancias, para decidir con evidencia | M | H-011-3 |
| H-011-6 | Como organizador, quiero ver el avance de cada evaluador (por ejemplo «Evaluador Ejemplo: 80 % de evaluaciones hechas, 8 de 10») y cerrar el periodo de evaluación, para dar seguimiento y recordarle a quien se atrasa | S | H-011-3 |
| H-011-7 | Como sistema, quiero asignaciones de usuarios a eventos con rol (`event_assignments`), para que evaluadores y coordinadores vean solo su evento | M | H-001-3 (plan 001) |
| H-011-8 | Como organizador, quiero invitar a un evaluador por correo con el rol `reviewer` y un enlace para establecer su contraseña, para que entre a su panel sin ver el de organizadores | M | H-011-7, H-008-6, H-014-1 |

## 2. Punto de partida

Estado esperado al iniciar (se mide ese día, no se supone): R0 en producción; propuestas en estado `submitted`; roles por organización con Policies (plan 001); catálogo de correos (plan 008); definición de formularios con el atributo `identifies_author` (plan 005); la Action `MarkSubmissionUnderReview` (plan 012, H-012-1). Del cpanel de origen se conserva la idea (rúbrica 1 a 5, tipos escala y texto libre, avance por evaluador) y no el código. `[PENDIENTE: medir el volumen de propuestas 2027 al cierre para decidir si hace falta asignación automática]`.

## 3. Decisiones

### DA-1 · Roles — se recomienda **roles de organización con *team* de spatie y acceso al evento por `event_assignments`**

Roles de organización (`spatie/laravel-permission` con la organización como *team*): `org_admin`, `organizer`, `reviewer` y `platform`. El rol `reviewer` **no tiene ningún permiso del panel de organizadores**: solo existe para entrar a su propio panel. El acceso a un evento concreto lo da `event_assignments (user_id, event_id, role)` con rol de evento `reviewer` o `coordinator` (el rol `speaker` llega en R2), consultado por las Policies. Alternativa descartada: un rol de organización «evaluador» con acceso a todos los eventos.

Permisos que introduce este plan (nombres `entidad.accion`): `rubrics.manage`, `review_assignments.manage`, `reviews.view_results`, `reviews.view_progress`, `reviews.close_period`, `event_assignments.manage`, `reviewers.invite`. La matriz rol × permiso se siembra en el plan 001.

### DA-2 · Versionado de la rúbrica — se recomienda **congelar la versión al primer uso**

Una rúbrica con evaluaciones registradas no se edita: se crea una versión nueva y las evaluaciones guardan la versión con que se hicieron. Alternativa descartada: editar en sitio y recalcular, que cambia resultados ya vistos.

### DA-3 · Escala — se recomienda **escala numérica configurable por criterio (mínimo y máximo enteros), con 1 a 5 como valor por defecto**

Se conserva la escala del cpanel (1 Excelente a 5 Malo) como plantilla, pero la dirección (mayor es mejor) se define por la rúbrica para que el promedio ponderado sea inequívoco. `[PENDIENTE: confirmar si la ANPR quiere conservar la escala invertida del cpanel (1 Excelente)]`.

### DA-4 · Revisión ciega — se recomienda **opción por evento y tipo de propuesta, con campos marcados por el organizador**

En modo ciego el evaluador no ve nombre, correo, empresa, país ni biografía de los autores, ni los campos de formulario con `identifies_author` (atributo definido en el plan 005). El título y los campos de texto libre **no se filtran automáticamente**: el organizador marca los que puedan delatar al autor, y la pantalla de activación del modo ciego lo advierte. Los archivos se entregan al evaluador con un **nombre neutro** (`propuesta-<número>.<extensión>`), no con el nombre original. Los metadatos internos de PDF y PPTX pueden contener al autor: es un riesgo declarado en §8, no resuelto en este plan. Alternativa descartada: ciego siempre, que impide eventos donde conocer al autor es parte del criterio.

### DA-5 · Cuándo una propuesta pasa a `under_review` — se recomienda **al recibir su primera asignación**

`AssignReviewers` solo acepta propuestas con `origin = call`: un conferencista invitado (plan 018) nace `accepted` y no se evalúa. `AssignReviewers` llama a `MarkSubmissionUnderReview` (plan 012) para pasar de `submitted` a `under_review` con historial. Un evento sin comité no pasa por aquí: el plan 012 permite decidir desde `submitted`.

### DA-6 · Alcances de la asignación — se recomienda **tres: propuesta, eje y convocatoria, con regla permanente**

Se asigna a una **propuesta** (individual o en lote desde la lista del plan 009), a un **eje** (`Track`) o a una **convocatoria** (`CallForSubmissions`). Un evaluador asignado a un alcance evalúa **todas** las propuestas del alcance; no hay reparto automático balanceado (R2). Asignar por eje o convocatoria guarda además una **regla de alcance** (`review_assignment_scopes`): cada propuesta que llegue después dentro del alcance se asigna sola, mediante un job idempotente que se encola al enviarse la propuesta. Quitar la regla no borra las asignaciones ya hechas. Alternativa descartada: solo instantánea con un botón «Asignar propuestas nuevas», que depende de que alguien lo recuerde mientras la convocatoria sigue abierta.

### DA-7 · Quitar una asignación — se recomienda **solo `pending` se elimina; con evaluación en curso o enviada se cancela**

Una asignación `pending` se elimina. Si el evaluador ya empezó o envió, pasa a `cancelled` y se conserva su evaluación, que deja de contar en los resultados. Exige ampliar el `CHECK` de estado con `cancelled` (§4, propuesta de datos).

### DA-8 · Aviso por correo — se recomienda **un resumen por evaluador y por operación**

`reviewer.assignment` es un solo correo por evaluador y por operación de asignación («te asignaron N propuestas», con el enlace a su tablero), no uno por propuesta. Una propuesta que llega después por una regla de alcance no genera correo (el evaluador la ve en su tablero). Alternativa descartada: un correo por propuesta, que satura al evaluador en una asignación por convocatoria.

## 4. Contrato

- **Rutas web (Filament, sin JSON público):** recursos `RubricResource`, `ReviewAssignmentResource` (pantalla «Asignación» del evento con tres vistas: por alcance, individual y por evaluador), `ReviewResultsPage`, `ReviewerProgressPage` e invitación de evaluadores en el panel de organizadores; panel `Reviewer` (un panel por rol, ADR-0004) con la página de inicio `ReviewerDashboard` y `AssignedSubmissionResource`. Sin entradas nuevas en `docs/api/endpoints.md`.
- **Form Requests:** `StoreRubricRequest`, `PublishRubricRequest`, `AssignReviewersRequest` (alcance `submission|track|call`), `RemoveReviewAssignmentRequest`, `SubmitReviewRequest` (valida cada respuesta contra los criterios de la versión), `DeclareConflictRequest`, `CloseReviewPeriodRequest`, `InviteReviewerRequest`.
- **Actions:** `PublishRubric`, `AssignReviewers`, `SyncScopedAssignments` (idempotente; la usan la creación de la regla, el envío de una propuesta nueva y un botón manual), `RemoveReviewAssignment`, `SubmitReview` (transaccional), `DeclareConflictOfInterest`, `ComputeSubmissionResults`, `CloseReviewPeriod`, `AssignUserToEvent`, `InviteReviewer`.
- **Correos nuevos en el catálogo del plan 008:** `reviewer.invitation` (con enlace para establecer contraseña, mismo mecanismo y caducidad que `team.set_password` del plan 003) y `reviewer.assignment` (resumen por evaluador y operación, DA-8), ambos críticos, con predeterminadas es/en.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* evaluar propuestas con criterios propios del evento y acceso acotado; dueño: módulo de evaluación; ciclo de vida: vigente hasta archivar el evento.
  2. *Modelo:* `rubrics` (evento, tipo de propuesta, versión, estado `draft|published`, `blind_review` bool, `min_reviewers`, `discrepancy_threshold` en unidades de la escala); `rubric_criteria` (rúbrica, posición, `label` traducible, `scale_min`, `scale_max`, `weight`, `kind` `scale|text`); `event_assignments` (usuario, evento, rol `reviewer|coordinator`, único por usuario, evento y rol); `review_assignments` (propuesta, evaluador, estado `pending|in_progress|submitted|declined_conflict|cancelled`, `assigned_by`, `assigned_at`, `source` `manual|scope`); `review_assignment_scopes` (evento, evaluador, tipo de alcance `track|call`, identificador del alcance, `created_by`; único por evaluador y alcance; claves compuestas para que el eje o la convocatoria pertenezcan al mismo evento y organización); `reviews` (asignación, versión de rúbrica, enviada en, `revision` entero); `review_answers` (evaluación, criterio, valor entero o texto); `review_history` de negocio para reevaluaciones. Todas con `organization_id` NOT NULL, UUID, y `event_id` donde aplique; ámbito: organización y evento.
  2b. *Concreción de `event_assignments` y `rubrics` (F0):* `event_assignments(id uuid, organization_id, event_id, user_id, role, created_at, updated_at)` con `role` `string` y `CHECK (role IN ('reviewer','coordinator'))` (`speaker` llega en R2); `unique(id, organization_id)`; `unique(event_id, user_id, role)`; FK compuesta `(event_id, organization_id)` hacia `events` y FK compuesta `(organization_id, user_id)` hacia `organization_user` (garantiza la membresía en la base; el estado `deactivated` lo vigilan la Action y la Policy); trigger de inmutabilidad de `organization_id`. `rubrics(id, organization_id, event_id, submission_type_id, version, status, blind_review, min_reviewers, discrepancy_threshold)` con `unique(event_id, submission_type_id, version)`. Permisos nuevos en `RolePermissions` con migración de sincronización; el rol de evento `coordinator` se resuelve en las Policies consultando `event_assignments`, no con spatie.
  3. *Integridad:* claves foráneas compuestas para que propuesta, evaluador y evento compartan la organización (con `unique(id, organization_id)` en las tablas referenciadas); `CHECK` de `scale_min < scale_max`, `weight > 0` y `discrepancy_threshold > 0`; único por `(submission_id, reviewer_id)`; `SoftDeletes` en `reviews`.
  4. *Cambio:* tablas nuevas, sin datos existentes; migraciones generadas por el agente y aplicadas por una persona.
  5. *Alternativa descartada:* guardar la rúbrica como `jsonb` dentro del evento; impide consultas de resultados y versionado limpio.
  6. *Pregunta de negocio:* ver DA-3.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` y coordinador de evento: crear y publicar rúbricas, asignar, invitar evaluadores (la asignación y su regla de alcance, `review_assignments.manage`, y el avance por evaluador, `reviews.view_progress`, los tiene también `organizer`; DA-6), ver resultados, cerrar periodo. `reviewer` (con asignación de evento): ver solo sus propuestas asignadas, declarar conflicto, calificar y reevaluar mientras el periodo esté abierto; ningún permiso del panel de organizadores. Autor y público: nada |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `RubricPolicy`, `ReviewAssignmentPolicy`, `ReviewPolicy`, `EventAssignmentPolicy`; `tests/Feature/Reviews/` con una prueba por rol y acción, permitido y denegado |
| ¿Hay modelos nuevos con datos de una organización? | Sí, todos los de §4; usan `BelongsToOrganization` y se cubren con `ModelsAreScopedTest` y `TenantIsolationTest` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Depende de `event_assignments`: un evaluador de un evento no ve otro; un identificador ajeno responde 404 |
| ¿Datos personales nuevos? | Calificaciones y comentarios de evaluadores (base: interés legítimo, `docs/privacidad.md`); correo y nombre del evaluador; se añaden al inventario en el mismo cambio. Retención `[PENDIENTE: plazo]`; se anonimizan con la persona (plan 014) |
| ¿Se suben archivos? | No; el evaluador descarga los de la propuesta mediante el plan 007, con enlace temporal, nombre neutro y auditoría |
| ¿Hay dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos? | `reviewer.invitation` y `reviewer.assignment`, por cola, críticos |
| ¿Qué se registra en la auditoría? | Alta y baja de asignaciones, invitaciones, cambios de rol de evento, publicación de rúbrica, conflictos declarados, reevaluaciones, cierre del periodo y exportación de resultados |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos aprobada (2026-11-13); Policies y guardianas con modelos vacíos | S |
| F1 | `event_assignments` y su Policy (H-011-7) | M |
| F2 | Rúbrica, criterios, versionado y publicación (H-011-1) | M |
| F3a | Asignación individual y en lote, informe de omitidas, paso a `under_review`, quitar y cancelar, correo resumen (H-011-2; CA-011-5 a 10, 38 a 41, 44 a 47); depende de H-012-1 | M |
| F3b | Alcances por eje y convocatoria con regla permanente y job idempotente, vista por evaluador e invitación desde la pantalla (H-011-2, H-011-8; CA-011-42, 43, 48, 49) | M |
| F4 | Panel del evaluador con su tablero (resumen, lista, filtros, estados vacío y cerrado) y `SubmitReview` transaccional (H-011-3; CA-011-50 a 54) | M |
| F5 | Conflicto de interés y modo ciego (H-011-4) | M |
| F6 | Resultados, avance por evaluador y cierre del periodo (H-011-5, H-011-6; CA-011-25 y 55 a 60) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-011-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-011-1 · Rúbrica por evento y tipo de propuesta

- **CA-011-1:** *Dado* un organizador con `rubrics.manage`, *cuando* crea una rúbrica para un evento y un tipo de propuesta con criterios de escala, peso y `discrepancy_threshold`, *entonces* queda en borrador con sus criterios ordenados y traducibles.
- **CA-011-2:** *Dado* una rúbrica con un criterio de peso cero o negativo, con `scale_min >= scale_max`, o con `discrepancy_threshold` menor o igual que cero o mayor que la amplitud de la escala, *cuando* se intenta guardar, *entonces* se rechaza con error de validación.
- **CA-011-3:** *Dado* una rúbrica publicada con al menos una evaluación registrada, *cuando* el organizador la edita, *entonces* se crea una versión nueva y la anterior queda intacta con sus evaluaciones.
- **CA-011-4:** *Dado* un organizador de la organización A, *cuando* pide una rúbrica de la organización B por su identificador, *entonces* la respuesta es 404.

### H-011-2 · Asignación de evaluadores

Los CA-011-5 a 10 cubren el alcance por eje y las reglas comunes; los CA-011-38 a 49 cubren los otros alcances, la regla permanente y la pantalla.

- **CA-011-5:** *Dado* un evento con evaluadores asignados a un eje, *cuando* el organizador asigna ese eje, *entonces* cada propuesta del eje recibe al menos `min_reviewers` asignaciones `pending`.
- **CA-011-6:** *Dado* una propuesta con menos asignaciones que el mínimo, *cuando* se consulta el avance, *entonces* aparece marcada como «cobertura incompleta».
- **CA-011-7:** *Dado* un evaluador cuyo correo coincide (sin distinguir mayúsculas) con el del autor o con el de cualquier participante de una propuesta, *cuando* se intenta asignarle esa propuesta, *entonces* se rechaza.
- **CA-011-8:** *Dado* una asignación nueva, *cuando* se crea, *entonces* queda registro de auditoría y se encola un solo `reviewer.assignment` por evaluador y operación (DA-8, CA-011-44); si el correo falla, la asignación persiste y el fallo es visible.
- **CA-011-9:** *Dado* una propuesta `submitted`, *cuando* recibe su primera asignación, *entonces* pasa a `under_review` mediante `MarkSubmissionUnderReview` con historial; una propuesta que ya está `under_review` no cambia de estado ni duplica el historial.
- **CA-011-10:** *Dado* un usuario sin `event_assignments` de rol `reviewer` en el evento de la propuesta, *cuando* se intenta asignarle una propuesta, *entonces* se rechaza.
- **CA-011-38:** *Dado* un integrante con `review_assignments.manage` y una convocatoria con propuestas de varios ejes, *cuando* asigna a dos evaluadores esa convocatoria, *entonces* cada evaluador recibe una asignación `pending` por cada propuesta elegible de la convocatoria y queda guardada la regla de alcance.
- **CA-011-39:** *Dado* la lista de propuestas del evento con filtros, *cuando* el integrante marca varias y usa «Asignar evaluadores», *entonces* solo esas propuestas se asignan (`source = manual`) y no se crea regla de alcance.
- **CA-011-40:** *Dado* un alcance con propuestas retiradas, invitadas (`origin = invited`) o en un estado distinto de `submitted` y `under_review`, *cuando* se asigna, *entonces* esas propuestas se omiten y el informe de la operación indica cuántas y por qué.
- **CA-011-41:** *Dado* una asignación que omite parejas por conflicto de correo (CA-011-7), *cuando* termina, *entonces* el informe lista cada pareja omitida con su motivo y las demás asignaciones se crean; nunca se falla en silencio.
- **CA-011-42:** *Dado* una regla de alcance vigente, *cuando* llega una propuesta nueva dentro del alcance, *entonces* un job idempotente asigna a los evaluadores de la regla (sin duplicar si se ejecuta dos veces) y la propuesta pasa a `under_review` (CA-011-9).
- **CA-011-43:** *Dado* una regla de alcance, *cuando* se quita, *entonces* las asignaciones ya creadas se conservan y las propuestas nuevas dejan de asignarse.
- **CA-011-44:** *Dado* una operación que asigna doce propuestas a un evaluador, *cuando* termina, *entonces* se encola un solo correo `reviewer.assignment` con la cantidad y el enlace a su tablero; una propuesta asignada después por una regla no envía correo.
- **CA-011-45:** *Dado* una asignación `pending`, *cuando* el integrante la quita, *entonces* se elimina y queda auditada; si está `in_progress` o `submitted`, pasa a `cancelled`, se conserva la evaluación y deja de contar en los resultados.
- **CA-011-46:** *Dado* un usuario sin `review_assignments.manage` (incluido un evaluador), *cuando* intenta asignar o quitar por cualquiera de los alcances, *entonces* se deniega en el servidor aunque la ruta se invoque directamente.
- **CA-011-47:** *Dado* una convocatoria, un eje o una propuesta de otro evento o de otra organización, *cuando* se intenta asignar por su identificador, *entonces* la respuesta es 404 y no se crea nada.
- **CA-011-48:** *Dado* la vista «por evaluador», *cuando* se abre, *entonces* muestra por evaluador el número de propuestas asignadas y su avance, y un alcance con menos evaluadores que `min_reviewers` aparece como «cobertura incompleta» (CA-011-6).
- **CA-011-49:** *Dado* un evaluador que aún no tiene asignación de evento, *cuando* el integrante lo elige en la pantalla de asignación, *entonces* la pantalla ofrece invitarlo (H-011-8) y no crea ninguna asignación de propuesta hasta que exista su `event_assignments`.

### H-011-3 · Panel del evaluador y calificación transaccional

- **CA-011-11:** *Dado* un evaluador con dos propuestas asignadas en un evento con diez, *cuando* abre su panel, *entonces* ve solo esas dos.
- **CA-011-12:** *Dado* un evaluador, *cuando* abre una propuesta que no tiene asignada (por su identificador), *entonces* la respuesta es 404.
- **CA-011-13:** *Dado* una rúbrica publicada, *cuando* el evaluador envía todas las respuestas válidas, *entonces* se guarda la evaluación y sus respuestas en una sola transacción y la asignación pasa a `submitted`.
- **CA-011-14:** *Dado* un envío con un valor fuera de la escala o un criterio obligatorio vacío, *cuando* se guarda, *entonces* se rechaza y no queda ningún dato parcial.
- **CA-011-15:** *Dado* una evaluación enviada y el periodo abierto, *cuando* el evaluador reevalúa, *entonces* se incrementa `revision`, la versión anterior se conserva en el historial y los resultados usan la última.
- **CA-011-16:** *Dado* un usuario con rol `reviewer` y sesión con 2FA, *cuando* abre el panel de organizadores o cualquier recurso de propuestas de ese panel, *entonces* la respuesta es 403.
- **CA-011-50:** *Dado* un evaluador con asignaciones en distintos estados, *cuando* abre su panel, *entonces* la página de inicio muestra el total de asignadas y cuántas están pendientes, en progreso, enviadas y con conflicto declarado, y si el periodo de evaluación está abierto o cerrado.
- **CA-011-51:** *Dado* un evaluador con más de una página de asignaciones, *cuando* abre su lista, *entonces* se pagina con tope de 100, se ordena con las pendientes primero y se filtra por estado, eje y convocatoria.
- **CA-011-52:** *Dado* un evaluador sin asignaciones, o con el periodo cerrado, *cuando* abre su panel, *entonces* ve un estado vacío o un aviso de periodo cerrado que lo explica, y las acciones de calificar no están disponibles.
- **CA-011-53:** *Dado* un evaluador con una asignación `cancelled` o `declined_conflict`, *cuando* abre su lista, *entonces* la cancelada no aparece y la de conflicto se muestra sin acción de calificar.
- **CA-011-54:** *Dado* un evaluador con el panel abierto en un evento con modo ciego, *cuando* se pide al servidor la lista del tablero, *entonces* no contiene nombre ni correo de autores (aplica CA-011-19 al tablero).

### H-011-4 · Conflicto de interés y revisión ciega

- **CA-011-17:** *Dado* un evaluador con una propuesta asignada, *cuando* declara conflicto de interés, *entonces* la asignación pasa a `declined_conflict`, no puede calificarla y los organizadores ven el aviso.
- **CA-011-18:** *Dado* un evento con revisión ciega activa, *cuando* el evaluador abre una propuesta, *entonces* no ve nombre, correo, empresa, país ni biografía de los autores ni los campos con `identifies_author`, ni en el detalle ni en las exportaciones de su panel.
- **CA-011-19:** *Dado* el modo ciego y una propuesta con datos de autor conocidos, *cuando* una prueba automática pide al servidor la página y los datos del panel del evaluador, *entonces* ni el nombre, ni el correo, ni los valores de los campos con `identifies_author` aparecen en ningún fragmento de la respuesta (HTML, atributos ni cargas de datos); el ocultamiento es del servidor, no del estilo.
- **CA-011-20:** *Dado* un archivo cuyo nombre original contiene el nombre del autor, *cuando* un evaluador lo descarga, *entonces* `Content-Disposition` lleva el nombre neutro `propuesta-<número>.<extensión>` y no el original, también fuera del modo ciego.
- **CA-011-21:** *Dado* un organizador que activa el modo ciego, *cuando* guarda la configuración, *entonces* ve la advertencia de que el título y los campos de texto libre no se filtran salvo que los marque con `identifies_author`.
  Manual: activar el modo ciego en `staging`, leer el aviso a ~375 px y ~1280 px y marcar un campo como «identifica al autor».

### H-011-5 · Resultados ponderados

- **CA-011-22:** *Dado* una propuesta con tres evaluaciones y criterios con pesos distintos, *cuando* se calculan los resultados, *entonces* el promedio ponderado y la desviación coinciden con el cálculo de referencia de la prueba.
- **CA-011-23:** *Dado* una propuesta con menos evaluaciones que el mínimo, *cuando* se muestran los resultados, *entonces* se marca como «incompleta» y no se ordena en el ranking como definitiva.
- **CA-011-24:** *Dado* una propuesta en la que dos evaluadores difieren en algún criterio más que `discrepancy_threshold` de la rúbrica, *cuando* se abre la vista de discrepancias, *entonces* aparece listada con las calificaciones de cada evaluador; sin `reviews.view_results` la vista se deniega.

### H-011-6 · Avance y cierre del periodo

- **CA-011-25:** *Dado* varios evaluadores con asignaciones, *cuando* el organizador con `reviews.view_progress` abre el avance, *entonces* ve por evaluador su nombre, el porcentaje de evaluaciones hechas y el conteo («80 % · 8 de 10»).
- **CA-011-26:** *Dado* un periodo abierto, *cuando* el organizador lo cierra, *entonces* los evaluadores ya no pueden crear ni cambiar evaluaciones (error visible) y queda auditado quién y cuándo.
- **CA-011-27:** *Dado* un periodo cerrado, *cuando* un organizador con `reviews.close_period` lo reabre, *entonces* se permite y queda auditado; sin el permiso, se deniega.
- **CA-011-55:** *Dado* un evaluador con diez asignaciones, de las cuales ocho están `submitted`, una `in_progress` y una `pending`, *cuando* el organizador abre el avance, *entonces* ve 80 % y el desglose por estado (enviadas, en progreso, pendientes y con conflicto declarado).
- **CA-011-56:** *Dado* asignaciones `cancelled` y `declined_conflict`, *cuando* se calcula el porcentaje, *entonces* las canceladas no cuentan ni en el numerador ni en el denominador, y las de conflicto declarado se cuentan aparte y no restan al porcentaje; un evaluador sin asignaciones activas muestra «sin asignaciones» y no un 0 %.
- **CA-011-57:** *Dado* la página de avance, *cuando* el organizador la abre, *entonces* puede ordenar por porcentaje y filtrar por evaluador, eje y convocatoria, ve el avance global del evento y los evaluadores con avance menor a un umbral aparecen resaltados.
- **CA-011-58:** *Dado* un evaluador en la lista de avance, *cuando* el organizador abre su detalle, *entonces* ve la lista de sus propuestas con el estado de cada asignación y la fecha de su última actividad, sin mostrar las calificaciones ni los comentarios (eso requiere `reviews.view_results`).
- **CA-011-59:** *Dado* un usuario sin `reviews.view_progress` (incluido un evaluador), *cuando* abre la página de avance o su detalle por la ruta, *entonces* se deniega en el servidor; un evaluador de otro evento u organización no aparece y su identificador responde 404.
- **CA-011-60:** *Dado* un evaluador, *cuando* abre su propio tablero, *entonces* ve su propio porcentaje de avance y nunca el de otros evaluadores.

### H-011-7 · Asignaciones de usuarios a eventos

- **CA-011-28:** *Dado* un usuario con una asignación de evaluador al evento X, *cuando* intenta abrir el evento Y de la misma organización (o un identificador de otra organización), *entonces* la respuesta es 404 y no ve datos de Y.
- **CA-011-29:** *Dado* un administrador de la organización A con `event_assignments.manage`, *cuando* asigna a un usuario con membresía en A a un evento de A, *entonces* se crea la asignación; si el usuario no es miembro de A o el evento es de B, se rechaza.
- **CA-011-30:** *Dado* una asignación duplicada para el mismo usuario, evento y rol, *cuando* se crea, *entonces* se rechaza por la restricción única.
- **CA-011-31:** *Dado* un cambio de rol de evento, *cuando* se aplica, *entonces* queda registro de auditoría con quién, a quién, rol anterior y nuevo.
- **CA-011-32:** *Dado* una asignación con rol `speaker` (que no existe en R1), *cuando* se intenta crear, *entonces* se rechaza con error de validación.

### H-011-8 · Invitación del evaluador

- **CA-011-33:** *Dado* un organizador con `reviewers.invite` y un evaluador externo, *cuando* lo invita a un evento con su correo, *entonces* se crea la cuenta con el rol `reviewer` en la organización, su asignación de evento de rol `reviewer`, y se encola `reviewer.invitation` con un enlace para establecer contraseña; queda auditado.
- **CA-011-34:** *Dado* que la 2FA obligatoria (H-014-1) no está activa en el entorno, *cuando* se intenta invitar a un evaluador externo, *entonces* la invitación se rechaza con un mensaje que explica la causa y no se crea ninguna cuenta.
- **CA-011-35:** *Dado* un correo con formato inválido o un evento de otra organización, *cuando* se intenta invitar, *entonces* se rechaza con error de validación o 404 respectivamente.
- **CA-011-36:** *Dado* un correo que ya pertenece a la organización, *cuando* se invita al evento, *entonces* no se duplica la cuenta, se añade la asignación de evento y se avisa sin reenviar un enlace de contraseña.
- **CA-011-37:** *Dado* un enlace de `reviewer.invitation` usado o caducado, *cuando* se abre, *entonces* se rechaza con un mensaje genérico y un evaluador cuyo enlace caducó puede pedir uno nuevo sin revelar si el correo existe.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Fuga de datos de autores en modo ciego | Ocultamiento en el servidor, prueba automática de la respuesta y nombre neutro de archivo (CA-011-18 a 20); revisión humana |
| Los metadatos internos de un PDF o PPTX delatan al autor | Riesgo declarado: se advierte al organizador (CA-011-21); eliminar metadatos queda como deuda `[PENDIENTE: decidir con el plan 007 si se limpian al subir]` |
| Un evaluador ve propuestas de otro evento o el panel de organizadores | `event_assignments` en las Policies y rol `reviewer` sin permisos de organizador (CA-011-11, 12, 16, 28) |
| Cambiar la rúbrica después de evaluar altera resultados | Versionado al primer uso (CA-011-3) |
| Acceso de evaluadores externos sin 2FA | H-011-3 y H-011-8 dependen de H-014-1; la invitación se bloquea hasta que esté activa (CA-011-34) |
| Receso de fin de año y R0 retrasado al 4-dic | Prioridad declarada en §1: este plan y el 012 antes del 21-ene; primero H-011-7, H-012-1 y H-014-1; recorte de H-011-4 antes que cualquier Policy |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | 39161b8 | 2026-11-13 | Plan aprobado por Antonio; propuesta de datos de event_assignments y rubrics concretada. |
| F1 (H-011-7) | 3d262fa | 2026-11-13 | 14 pruebas nuevas en verde (CA-011-29 a 32); guardianas Arch, Tenancy, LangParity y RolesMatrix: 239 pruebas en verde; Pint OK en 15 archivos; Larastan sin errores; check:criteria 0 errores. Desviaciones: (1) sin UI Filament (queda para el panel Reviewer). (2) CA-011-28 probado en el modelo (scope assignedTo), el 404 por ruta llega con el panel Reviewer (F4). (3) CA-011-10 parcial (isReviewerOf listo; el rechazo exige review_assignments, F3). CA-011-16 no cubierto (panel Reviewer y 2FA). Pruebas corridas en contenedor desechable con el repo montado, porque eventos-app-1 monta la copia WSL. No se ejecuto la suite completa. |
| F2 (H-011-1) | 72e2821 | 2026-11-17 | composer check completo en el contenedor: Pint OK en 507 archivos, Larastan sin errores, 1300 pruebas en verde (47 en tests/Feature/Reviews), check:criteria 0 errores. Migraciones aplicadas solo en la base de pruebas. Desviaciones: sin UI Filament (queda para F4); la costura RubricUsageChecker queda enlazada a NoRecordedReviews hasta que F4 traiga las evaluaciones.
