# feat: evaluación por comité (rúbrica, asignación, panel del evaluador y resultados)

> Plan de la épica E-11 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 011: todos sus criterios son `CA-011-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna (depende de RC-2: la 2FA debe estar activa antes de dar acceso a evaluadores externos; ver plan 014)

## 1. Objetivo y alcance

Reemplazar el motor de evaluación del cpanel de origen (rúbrica 1 a 5, evaluadores por tema, avance por evaluador; `docs/producto/analisis-cpanel-y-propuesta.md` §5.5) por uno con control de acceso real: rúbricas versionadas por evento y tipo de propuesta, asignación de evaluadores, panel propio del evaluador, conflicto de interés, revisión ciega, guardado transaccional con historial y resultados ponderados. Lo usa el equipo organizador para decidir y el comité para evaluar, antes del cierre de la convocatoria (meta: 18-dic-2026).

**Dentro:** `event_assignments` con roles de evento (ADR-0003 DA-1); rúbrica con criterios, escala y peso; asignación manual de evaluadores a ejes y a propuestas con mínimo de evaluadores; panel Filament del evaluador; declaración de conflicto de interés y modo ciego; reevaluación con historial; resultados (promedio ponderado, desviación, discrepancias); avance por evaluador y cierre del periodo.

**Fuera:** aceptar, rechazar y avisar (plan 012); asignación automática balanceada (pospuesta a R2); 2FA y RLS (plan 014); portal del ponente (R2).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-011-1 | Como organizador, quiero crear una rúbrica por evento y tipo de propuesta con criterios, escala y peso, para evaluar con mis reglas | M | E-06 (propuestas) |
| H-011-2 | Como organizador, quiero asignar evaluadores a ejes y propuestas con un mínimo por propuesta, para repartir la carga | M | H-011-7 |
| H-011-3 | Como evaluador, quiero un panel propio con mis propuestas asignadas y calificar con guardado transaccional, para evaluar sin ver lo ajeno | M | H-011-1, H-011-2 |
| H-011-4 | Como evaluador, quiero declarar conflicto de interés y, si el evento lo pide, evaluar en modo ciego, para una evaluación justa | M | H-011-3 |
| H-011-5 | Como organizador, quiero resultados con promedio ponderado, desviación y discrepancias, para decidir con evidencia | M | H-011-3 |
| H-011-6 | Como organizador, quiero ver el avance por evaluador y cerrar el periodo de evaluación, para dar seguimiento | S | H-011-3 |
| H-011-7 | Como sistema, quiero asignaciones de usuarios a eventos con rol (`event_assignments`), para que evaluadores y ponentes vean solo su evento | M | H-001-3 (plan 001) |

## 2. Punto de partida

Estado esperado al iniciar (se mide ese día, no se supone): R0 en producción; propuestas en estado `submitted`; roles por organización con Policies (plan 001); catálogo de correos (plan 008). Del cpanel de origen se conserva la idea (rúbrica 1 a 5, tipos escala y texto libre, avance por evaluador) y no el código. `[PENDIENTE: medir el volumen de propuestas 2027 al cierre para decidir si hace falta asignación automática]`.

## 3. Decisiones

### DA-1 · Rol de evaluador en el evento — se recomienda **`event_assignments` y no un rol de organización**

Los evaluadores son de un solo evento; los administradores, de toda la organización (ADR-0003 DA-1). El rol de organización sigue en `spatie/laravel-permission` con la organización como *team*; el acceso al evento lo da `event_assignments (user_id, event_id, role)`, consultado por las Policies. Alternativa descartada: un rol de organización «evaluador», que daría acceso a todos los eventos.

### DA-2 · Versionado de la rúbrica — se recomienda **congelar la versión al primer uso**

Una rúbrica con evaluaciones registradas no se edita: se crea una versión nueva y las evaluaciones guardan la versión con que se hicieron. Alternativa descartada: editar en sitio y recalcular, que cambia resultados ya vistos.

### DA-3 · Escala — se recomienda **escala numérica configurable por criterio (mínimo y máximo enteros), con 1 a 5 como valor por defecto**

Se conserva la escala del cpanel (1 Excelente a 5 Malo) como plantilla, pero la dirección (mayor es mejor) se define por la rúbrica para que el promedio ponderado sea inequívoco. `[PENDIENTE: confirmar si la ANPR quiere conservar la escala invertida del cpanel (1 Excelente)]`.

### DA-4 · Revisión ciega — se recomienda **opción por evento y tipo de propuesta**

En modo ciego el evaluador no ve nombre, correo, empresa, país ni biografía de los autores; los campos de formulario marcados como «identifican al autor» se ocultan. Alternativa descartada: ciego siempre, que impide eventos donde conocer al autor es parte del criterio.

## 4. Contrato

- **Rutas web (Filament, sin JSON público):** recursos `RubricResource`, `ReviewAssignmentResource`, `ReviewResultsPage` y `ReviewerProgressPage` en el panel de organizadores; panel `Reviewer` (un panel por rol, ADR-0004) con `AssignedSubmissionResource`. Sin entradas nuevas en `docs/api/endpoints.md`.
- **Form Requests:** `StoreRubricRequest`, `PublishRubricRequest`, `AssignReviewersRequest`, `SubmitReviewRequest` (valida cada respuesta contra los criterios de la versión), `DeclareConflictRequest`, `CloseReviewPeriodRequest`.
- **Actions:** `PublishRubric`, `AssignReviewers`, `SubmitReview` (transaccional), `DeclareConflictOfInterest`, `ComputeSubmissionResults`, `CloseReviewPeriod`, `AssignUserToEvent`.
- **Correos nuevos en el catálogo del plan 008:** `reviewer.invitation`, `reviewer.assignment` (críticas), con predeterminadas es/en.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* evaluar propuestas con criterios propios del evento y acceso acotado; dueño: módulo de evaluación; ciclo de vida: vigente hasta archivar el evento.
  2. *Modelo:* `rubrics` (evento, tipo de propuesta, versión, estado `draft|published`, `blind_review` bool, `min_reviewers`); `rubric_criteria` (rúbrica, posición, `label` traducible, `scale_min`, `scale_max`, `weight`, `kind` `scale|text`, `identifies_author` no aplica aquí); `event_assignments` (usuario, evento, rol `reviewer|speaker|coordinator`, único por usuario, evento y rol); `review_assignments` (propuesta, evaluador, estado `pending|in_progress|submitted|declined_conflict`); `reviews` (asignación, versión de rúbrica, enviada en, `revision` entero); `review_answers` (evaluación, criterio, valor entero o texto); `review_history` de negocio para reevaluaciones. Todas con `organization_id` NOT NULL, UUID, y `event_id` donde aplique; ámbito: organización y evento.
  3. *Integridad:* claves foráneas compuestas para que propuesta, evaluador y evento compartan la organización; `CHECK` de `scale_min < scale_max`; `weight > 0`; único por `(submission_id, reviewer_id)`; `SoftDeletes` en `reviews`.
  4. *Cambio:* tablas nuevas, sin datos existentes; migraciones generadas por el agente y aplicadas por una persona.
  5. *Alternativa descartada:* guardar la rúbrica como `jsonb` dentro del evento; impide consultas de resultados y versionado limpio.
  6. *Pregunta de negocio:* ver DA-3.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador y coordinador de evento: crear y publicar rúbricas, asignar, ver resultados, cerrar periodo. Evaluador: ver solo sus propuestas asignadas, declarar conflicto, calificar y reevaluar mientras el periodo esté abierto. Autor y público: nada |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `RubricPolicy`, `ReviewAssignmentPolicy`, `ReviewPolicy`, `EventAssignmentPolicy`; `tests/Feature/Reviews/` con una prueba por rol y acción, permitido y denegado |
| ¿Hay modelos nuevos con datos de una organización? | Sí, todos los de §4; usan `BelongsToOrganization` y se cubren con `ModelsAreScopedTest` y `TenantIsolationTest` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Depende de `event_assignments`: un evaluador de un evento no ve otro; un identificador ajeno responde 404 |
| ¿Datos personales nuevos? | Calificaciones y comentarios de evaluadores (base: interés legítimo, `docs/privacidad.md`); se añaden al inventario en el mismo cambio. Retención `[PENDIENTE: plazo]` |
| ¿Se suben archivos? | No; el evaluador descarga los de la propuesta mediante el plan 007, con enlace temporal y auditoría |
| ¿Hay dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos? | `reviewer.invitation` y `reviewer.assignment`, por cola, críticos |
| ¿Qué se registra en la auditoría? | Alta y baja de asignaciones, cambios de rol de evento, publicación de rúbrica, conflictos declarados, reevaluaciones, cierre del periodo y exportación de resultados |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos aprobada; Policies y guardianas con modelos vacíos | S |
| F1 | `event_assignments` y su Policy (H-011-7) | M |
| F2 | Rúbrica, criterios, versionado y publicación (H-011-1) | M |
| F3 | Asignación de evaluadores e invitación (H-011-2) | M |
| F4 | Panel del evaluador y `SubmitReview` transaccional (H-011-3) | M |
| F5 | Conflicto de interés y modo ciego (H-011-4) | M |
| F6 | Resultados, avance y cierre del periodo (H-011-5, H-011-6) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-011-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-011-1 · Rúbrica por evento y tipo de propuesta

- **CA-011-1:** *Dado* un organizador con permiso, *cuando* crea una rúbrica para un evento y un tipo de propuesta con criterios de escala y peso, *entonces* queda en borrador con sus criterios ordenados y traducibles.
- **CA-011-2:** *Dado* una rúbrica con un criterio de peso cero o negativo, o con `scale_min >= scale_max`, *cuando* se intenta guardar, *entonces* se rechaza con error de validación.
- **CA-011-3:** *Dado* una rúbrica publicada con al menos una evaluación registrada, *cuando* el organizador la edita, *entonces* se crea una versión nueva y la anterior queda intacta con sus evaluaciones.
- **CA-011-4:** *Dado* un organizador de la organización A, *cuando* pide una rúbrica de la organización B por su identificador, *entonces* la respuesta es 404.

### H-011-2 · Asignación de evaluadores

- **CA-011-5:** *Dado* un evento con evaluadores asignados a un eje, *cuando* el organizador asigna ese eje, *entonces* cada propuesta del eje recibe al menos `min_reviewers` asignaciones `pending`.
- **CA-011-6:** *Dado* una propuesta con menos asignaciones que el mínimo, *cuando* se consulta el avance, *entonces* aparece marcada como «cobertura incompleta».
- **CA-011-7:** *Dado* que el autor de una propuesta es también evaluador del evento, *cuando* se intenta asignarle su propia propuesta, *entonces* se rechaza.
- **CA-011-8:** *Dado* una asignación nueva, *cuando* se crea, *entonces* se encola `reviewer.assignment` al evaluador y queda registro de auditoría; si el correo falla, la asignación persiste y el fallo es visible.

### H-011-3 · Panel del evaluador y calificación transaccional

- **CA-011-9:** *Dado* un evaluador con dos propuestas asignadas en un evento con diez, *cuando* abre su panel, *entonces* ve solo esas dos.
- **CA-011-10:** *Dado* un evaluador, *cuando* abre una propuesta que no tiene asignada (por su identificador), *entonces* la respuesta es 404.
- **CA-011-11:** *Dado* una rúbrica publicada, *cuando* el evaluador envía todas las respuestas válidas, *entonces* se guarda la evaluación y sus respuestas en una sola transacción y la asignación pasa a `submitted`.
- **CA-011-12:** *Dado* un envío con un valor fuera de la escala o un criterio obligatorio vacío, *cuando* se guarda, *entonces* se rechaza y no queda ningún dato parcial.
- **CA-011-13:** *Dado* una evaluación enviada y el periodo abierto, *cuando* el evaluador reevalúa, *entonces* se incrementa `revision`, la versión anterior se conserva en el historial y los resultados usan la última.

### H-011-4 · Conflicto de interés y revisión ciega

- **CA-011-14:** *Dado* un evaluador con una propuesta asignada, *cuando* declara conflicto de interés, *entonces* la asignación pasa a `declined_conflict`, no puede calificarla y los organizadores ven el aviso.
- **CA-011-15:** *Dado* un evento con revisión ciega activa, *cuando* el evaluador abre una propuesta, *entonces* no ve nombre, correo, empresa, país ni biografía de los autores ni los campos marcados como «identifican al autor», ni en el detalle ni en las exportaciones del panel del evaluador.
- **CA-011-16:** *Dado* el modo ciego, *cuando* se inspecciona la respuesta HTTP y el HTML del panel del evaluador, *entonces* los datos ocultos no aparecen en ningún atributo ni payload (el ocultamiento es del servidor, no del estilo).
  Manual: abrir una propuesta con datos de autor como evaluador en modo ciego, ver el código fuente de la página a ~375 px y ~1280 px y buscar el nombre del autor.

### H-011-5 · Resultados ponderados

- **CA-011-17:** *Dado* una propuesta con tres evaluaciones y criterios con pesos distintos, *cuando* se calculan los resultados, *entonces* el promedio ponderado y la desviación coinciden con el cálculo de referencia de la prueba.
- **CA-011-18:** *Dado* una propuesta con menos evaluaciones que el mínimo, *cuando* se muestran los resultados, *entonces* se marca como «incompleta» y no se ordena en el ranking como definitiva.
- **CA-011-19:** *Dado* una propuesta en la que dos evaluadores difieren más que un umbral configurable, *cuando* se abre la vista de discrepancias, *entonces* aparece listada con las calificaciones de cada evaluador; la vista exige permiso de resultados.

### H-011-6 · Avance y cierre del periodo

- **CA-011-20:** *Dado* varios evaluadores con asignaciones, *cuando* el organizador abre el avance, *entonces* ve por evaluador el porcentaje de asignaciones enviadas.
- **CA-011-21:** *Dado* un periodo abierto, *cuando* el organizador lo cierra, *entonces* los evaluadores ya no pueden crear ni cambiar evaluaciones (error visible) y queda auditado quién y cuándo.
- **CA-011-22:** *Dado* un periodo cerrado, *cuando* un organizador con permiso lo reabre, *entonces* se permite y queda auditado; sin permiso, se deniega.

### H-011-7 · Asignaciones de usuarios a eventos

- **CA-011-23:** *Dado* un usuario con una asignación de evaluador al evento X, *cuando* intenta abrir el evento Y de la misma organización, *entonces* la respuesta es 404 o 403 según la Policy y no ve datos de Y.
- **CA-011-24:** *Dado* un administrador de la organización A, *cuando* asigna a un usuario con membresía en A a un evento de A, *entonces* se crea la asignación; si el usuario no es miembro de A o el evento es de B, se rechaza.
- **CA-011-25:** *Dado* una asignación duplicada para el mismo usuario, evento y rol, *cuando* se crea, *entonces* se rechaza por la restricción única.
- **CA-011-26:** *Dado* un cambio de rol de evento, *cuando* se aplica, *entonces* queda registro de auditoría con quién, a quién, rol anterior y nuevo.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Fuga de datos de autores en modo ciego | Ocultamiento en el servidor y CA-011-15 y 16; revisión humana |
| Un evaluador ve propuestas de otro evento | `event_assignments` en las Policies; prueba por rol (CA-011-9, 10, 23) |
| Cambiar la rúbrica después de evaluar altera resultados | Versionado al primer uso (CA-011-3) |
| Acceso de evaluadores externos sin 2FA | Dependencia explícita del plan 014 (RC-2): no se invita a evaluadores externos hasta cerrarla |
| Receso de fin de año | Meta 18-dic-2026; si no cabe, recorte de H-011-4 modo ciego, nunca de las Policies |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
