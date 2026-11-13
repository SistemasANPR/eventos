# feat: alta directa de conferencistas invitados

- **Fecha:** 2026-11-13
- **Estado:** Aprobado
- **Esfuerzo:** M
- **Deuda relacionada:** DT-12 y DT-13 (la FK de `submission_files` y la descarga llegan con los planes 006 y 009, de los que este plan depende); ninguna se cierra aquí

## 1. Objetivo y alcance

Hoy toda persona que presenta en un evento entra por una convocatoria: envía una propuesta que el comité evalúa. Los conferencistas invitados (magistrales, panelistas propuestos por el comité, ponentes de un patrocinador) no concursan y no tienen cómo existir en la plataforma. Este plan permite que el organizador los **dé de alta directamente**, con el mismo formulario que usan las propuestas y eligiendo el tipo (sesión, panel, póster, taller o los que el evento tenga), y que luego los vea **en una lista propia, separada de la de propuestas**, con el detalle completo.

Para quién: el equipo organizador (`org_admin` y `organizer`). Los invitados no usan la plataforma en este plan: su acceso es el portal del ponente del plan R2 (E-15).

**Dentro:**
- Alta de un invitado por el organizador: tipo, formulario, eje, participantes y respuestas dinámicas validadas con las mismas reglas que una propuesta.
- Distinguir el origen de una propuesta (`convocatoria` o `invitado`) en el modelo de datos de los planes 006 y 009.
- Vista «Invitados» separada de la vista «Propuestas» en el panel del evento, con filtros y paginación.
- Detalle del invitado: el mismo detalle de propuesta del plan 009 (respuestas con la versión del formulario con que se capturaron, participantes, archivos), marcado como invitado.
- Editar y retirar un invitado (H-018-4, «Debería»).
- Permiso nuevo `submissions.invite`, Policy, auditoría y pruebas de aislamiento.

**Fuera:**
- Portal, enlace de acceso y requisitos del ponente (R2, E-15 y E-16); aquí no se envía correo al invitado.
- Evaluación por comité y decisiones sobre invitados (planes 011 y 012): un invitado nace aceptado y no entra a esos flujos.
- Importación masiva (CSV o Excel) de invitados.
- Programa y agenda (R3, E-20).
- Un formulario distinto o simplificado para invitados: se usa el de las propuestas.
- Pago, hospedaje o viáticos del invitado.

## 2. Punto de partida

Inspeccionado el 2026-11-13 en `staging` (`29c5709`). Hechos, no suposiciones:

- **No existe nada de propuestas.** No hay modelo `Submission`, ni `SubmissionParticipant`, ni enum `SubmissionStatus`, ni tablas `submissions` o `submission_participants`. Los planes 006 (envío) y 009 (panel de propuestas) están en `Aprobado` con el registro de avance vacío. Este plan **depende de ellos**.
- **Existe `Contact`** (`app/Models/Contact.php`): `full_name`, `email`, `country`, único por `(organization_id, lower(email))`. El glosario lo define como la ficha reutilizable de ponentes.
- **Formularios dinámicos listos (plan 005, terminado en F0 a F5):** `FormDefinition` con campos en `jsonb`, versión y publicación; `BuildSubmissionRules` valida respuestas contra la definición; `LockedFormDefinition` bloquea la versión dentro de una transacción; `FormRenderer` (React) pinta el formulario en modo `preview` y `public`.
- **El formulario cuelga de la convocatoria**, no del evento ni del tipo: `call_for_submissions.form_definition_id` (publicar el formulario lo asigna a la convocatoria, `PublishFormDefinition`). Un invitado no tiene convocatoria, así que hoy no hay forma de resolver su formulario.
- **El tipo de propuesta es por evento** (`SubmissionType`: Sesión, Panel, Póster y Taller por defecto, con archivado) y hoy solo se alcanza a través de la convocatoria (`CallForSubmissions::submissionType()`).
- **El modelo propuesto en el plan 006** exige `call_for_submissions_id`, consentimiento (`consented_at`, `privacy_notice_version_id`) y `idempotency_key` en cada propuesta, y no tiene ninguna columna de origen ni de tipo.
- **`submission_files.call_id` es `NOT NULL`** con clave foránea a la convocatoria (migración `2026_11_10_500000`): un archivo de un invitado no podría guardarse.
- **Permisos** (`app/Support/Auth/RolePermissions.php`): `submissions.view`, `submissions.export` y `files.download` ya están en el rol organizador; no hay permiso para crear propuestas a mano. No existe `SubmissionPolicy` (la crea el plan 009).
- **El plan 009** lista por convocatoria y tipo, y su detalle y exportación suponen una convocatoria; un invitado no aparecería sin adaptarlo.
- **El plan 012** (decisiones) ya prevé entrada directa solo para propuestas importadas del plan B; ningún plan menciona invitados (búsqueda de «invit» en `docs/`).

## 3. Decisiones

Resumen: las nueve están cerradas el 2026-11-13 con la opción recomendada (aprobación de Antonio).

### DA-1 · Cómo se modela el invitado — se recomienda **una `Submission` con `origin = invited`**
**Cerrada el 2026-11-13: opción recomendada.**

- **A (recomendada):** el invitado es una propuesta cuyo origen es `invited` y que no tiene convocatoria. Reutiliza participantes, respuestas, versión del formulario, archivos, detalle, exportación, y más adelante el portal del ponente, el programa y los requisitos (R2 y R3), que ya cuelgan de la propuesta.
- **B:** tabla propia `invited_speakers`. Duplica respuestas, participantes y archivos, y obliga a que R2 y R3 manejen dos orígenes distintos.

### DA-2 · De dónde sale el formulario sin convocatoria — se recomienda **elegir un formulario publicado del evento, sugerido por tipo**
**Cerrada el 2026-11-13: opción recomendada.**

Al dar de alta, el organizador elige el **tipo** y el **formulario** (solo los publicados del evento). El sistema sugiere el de la convocatoria publicada más reciente de ese tipo y el organizador puede cambiarlo. Alternativas: usar siempre el formulario de una convocatoria concreta (obliga a existir una), o ligar el formulario al tipo (cambio de datos en el plan 005 ya terminado). La respuesta guarda `form_version` y `form_snapshot` como una propuesta (CA-006-12 y CA-006-13).

### DA-3 · Estado inicial del invitado — se recomienda **`accepted`**
**Cerrada el 2026-11-13: opción recomendada.**

El invitado no se evalúa: nace `accepted`, queda auditado quién lo dio de alta y cuándo, y el paso a `confirmed` lo da el ponente en R2. Alternativa: nacer `submitted` y obligar a pasar por el plan 012, lo que contradice «invitado» y lo mete en las colas de evaluación.

### DA-4 · Consentimiento y aviso de privacidad — se recomienda **declaración del organizador, sin correo en este plan**
**Cerrada el 2026-11-13: opción recomendada.**

El autor de una propuesta consiente al enviar (plan 006, H-006-6). El invitado no envía nada: el organizador **declara** que cuenta con la autorización de esa persona para registrar sus datos (`authorization_declared_at` y `registered_by_user_id`), y se guarda la versión vigente del aviso de privacidad solo como referencia. No se envía correo al invitado en este plan; se le informa y se le da acceso en R2 (E-15). Alternativa: enviar un correo de aviso al registrarlo, que obliga a una notificación nueva en el catálogo (ADR-0009) y a decidir si es crítica. **Riesgo:** entre el alta y R2 hay datos personales de alguien que aún no ha visto el aviso; se mitiga con la declaración, la auditoría y la vista de acceso restringido.

### DA-5 · Archivos de invitados — se recomienda **incluirlos en una fase propia (F4)**
**Cerrada el 2026-11-13: opción recomendada.**

Los campos de archivo del formulario exigen cambiar `submission_files.call_id` a opcional (con una restricción: o tiene convocatoria o es de un invitado). Hasta F4, un formulario con un campo de archivo obligatorio no se puede usar para invitados y el alta responde con un error claro. Alternativa: dejar los archivos fuera de este plan y registrar la deuda.

### DA-6 · Dónde vive el tipo de la propuesta — se recomienda **`submission_type_id` en `submissions` para ambos orígenes**
**Cerrada el 2026-11-13: opción recomendada.**

Hoy el tipo se deduce de la convocatoria. Para el invitado debe estar en la propuesta; para que la lista, los filtros y la exportación traten igual a los dos orígenes, el envío por convocatoria también copia el tipo a la propuesta al crearla. Alternativa: tipo nullable solo para invitados (dos rutas de consulta, más errores).

### DA-7 · Secuencia con los planes 006 y 009 — se recomienda **construir 018 después de 006 F0 y F1 y de 009 F0 a F2**
**Cerrada el 2026-11-13: opción recomendada.**

Las columnas nuevas (`origin`, `submission_type_id`, `registered_by_user_id`, `authorization_declared_at` y `call_for_submissions_id` opcional) deben entrar **en la migración de `submissions` del plan 006 si esa migración aún no se escribió**; si ya se aplicó, van en una migración propia de este plan. Mientras 006 y 009 no existan, este plan no se puede implementar. Los cambios a esos planes se listan en la sección 4.

### DA-8 · Interfaz de captura en el panel — se recomienda **incrustar `FormRenderer` en una página de Filament**
**Cerrada el 2026-11-13: opción recomendada.**

El panel de vista previa del plan 005 ya incrusta `FormRenderer`; se reutiliza para evitar mapear nueve tipos de campo a componentes de Filament y duplicar la lógica de idiomas y campos retirados. La validación es la del servidor (`BuildSubmissionRules`). Fase F0 incluye un spike corto: si incrustarlo para enviar (no solo previsualizar) resulta inviable, el respaldo es generar el formulario con componentes de Filament. Quedaría registrado como deuda.

### DA-9 · Exportación — se recomienda **que la exportación del plan 009 respete el filtro de origen y añada la columna «Origen»**
**Cerrada el 2026-11-13: opción recomendada.**

El filtro de origen existe en la lista; la exportación (H-009-3) exporta lo filtrado. Por defecto la vista «Propuestas» solo trae las de convocatoria y «Invitados» solo los invitados, de modo que cada exportación es de una sola población salvo que se quite el filtro.

## 4. Contrato

**Rutas.** Sin rutas JSON ni webhooks nuevos. Todo vive en el panel de Filament bajo `/{tenant}/events/{event}/…` (la ruta anidada del plan 009, DA-6 de ese plan); un evento de otra organización responde 404.

**Datos persistentes** (propuesta de `docs/database-design.md` §6; no se escribe ninguna migración hasta aprobar este plan):

1. **Necesidad.** El hecho de negocio «persona invitada por el organizador, sin convocatoria». Módulo dueño: propuestas (plan 006). Ciclo de vida: nace aceptada, se confirma en R2, se retira sin borrarse; se conserva como evidencia.
2. **Inspección.** Revisados: `contacts`, `call_for_submissions`, `form_definitions`, `submission_types`, `submission_files` y el modelo propuesto de `submissions` y `submission_participants` del plan 006.
3. **Modelo propuesto** (sobre `submissions`, ámbito evento):

| Columna | Detalle |
|---|---|
| `origin` | `string` con `CHECK` en (`call`, `invited`), `NOT NULL`, por defecto `call`; enum de PHP `SubmissionOrigin` |
| `call_for_submissions_id` | pasa a **nullable** |
| `submission_type_id` | clave foránea compuesta `(submission_type_id, organization_id)`, `NOT NULL`; para `call` se copia de la convocatoria al crear |
| `registered_by_user_id` | clave foránea a `users`, nula en `call`, obligatoria en `invited` |
| `authorization_declared_at` | `timestamptz`, nula en `call`, obligatoria en `invited` |
| `consented_at`, `privacy_notice_version_id` | pasan a nullable; obligatorias solo en `call` |

   Cambios en `submission_files` (solo F4): `call_id` pasa a nullable.

4. **Integridad.** `CHECK` de coherencia entre origen y columnas: en `call` la convocatoria, el consentimiento y la versión del aviso son obligatorios y no hay `registered_by_user_id`; en `invited` la convocatoria y el consentimiento son nulos y `registered_by_user_id` y `authorization_declared_at` son obligatorios. Índice `(event_id, origin, status)` para la lista. Claves foráneas compuestas con `organization_id`, como el resto. Historial: `spatie/laravel-activitylog` (ADR-0005 DA-2).
5. **Cambio.** Añadir columnas y relajar restricciones; sin datos que migrar porque `submissions` aún no existe. `down()` quita las columnas añadidas. **La migración la aplica el humano** a bases reales (acción 1 de `AGENTS.md`).
6. **Alternativa descartada.** Tabla `invited_speakers` aparte (DA-1): duplica participantes, respuestas y archivos.
7. **Preguntas de negocio.** Ninguna que bloquee el esquema.

**Cambios a otros planes** (se hacen en sus propios commits, con su especificación al día):
- **Plan 006:** la propuesta de datos de `submissions` incorpora las columnas de arriba; `SubmitSubmission` copia el tipo desde la convocatoria y fija `origin = call`.
- **Plan 009:** la lista y la exportación aceptan el filtro `origin`; la lista de propuestas se acota a `origin = call`; el detalle muestra una marca de «Invitado».
- **Plan 011:** `AssignReviewers` solo acepta propuestas `origin = call`.
- **Plan 012:** los correos de resultados y las decisiones masivas excluyen los invitados.
- **Glosario:** el término «Conferencista invitado» (propuesta con `origin = invited`) y `SubmissionOrigin`.
- **`docs/privacidad.md`:** `registered_by_user_id` y `authorization_declared_at` en el inventario de datos personales (F0).

**Form Requests y Actions.**
- `RegisterInvitedSpeakerRequest`: tipo, formulario, eje, participantes y `answers`; la organización, el evento, el origen, el estado y `registered_by_user_id` nunca vienen del cliente.
- Actions: `RegisterInvitedSpeaker` (transacción con `LockedFormDefinition`, igual que `SubmitSubmission`), `UpdateInvitedSpeaker` y `WithdrawSubmission` (la del plan 012, reutilizada).
- Tipos de las props del formulario incrustado en `resources/js/types/`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | **Dar de alta, editar y retirar invitados:** administrador de organización y organizador (`submissions.invite`, permiso nuevo). **Ver la lista y el detalle:** quien tenga `submissions.view` (organizador y administrador). **Exportar y descargar archivos:** `submissions.export` y `files.download`, sin cambios. Miembro sin esos permisos: nada. Visitante y autor: nada |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionPolicy` (la crea el plan 009): `viewAny`, `view`, `export` y los nuevos `invite`, `updateInvited` y `withdraw`. Prueba por rol, permitido y denegado, en `tests/Feature/Submissions/` |
| ¿Hay modelos nuevos con datos de una organización? | No hay modelo nuevo: se amplía `Submission`, que ya usa `BelongsToOrganization`. Se extienden `ModelsAreScopedTest` y `TenantIsolationTest` con propuestas de origen `invited` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Por ahora acceso de organización; el panel anidado por evento acota la consulta. Con `event_assignments` (plan 011) el coordinador de un evento solo vería los invitados de ese evento |
| ¿Se guardan datos personales nuevos? | Sí: nombre, correo, país, cargo, empresa y biografía del invitado (los mismos que un participante de propuesta), más `registered_by_user_id` y `authorization_declared_at`. **Base legal:** consentimiento declarado por el organizador y relación con la organización; **retención y borrado:** como `Contact` y propuestas (anonimización, H-014-4); `[PENDIENTE: plazo por categoría, plan 014]`. Se añaden al inventario de `docs/privacidad.md` |
| ¿Se suben archivos? | Solo en F4: los mismos tipos, tamaño y disco privado del plan 007 y la validación por contenido; URL temporal y `files.download` |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No en este plan (DA-4). El invitado recibe su primer correo con el portal de R2 |
| ¿Qué se registra en la auditoría? | Alta del invitado (quién, cuándo, tipo y formulario), edición con valor anterior y nuevo, retiro, y cada apertura del detalle (igual que CA-009-8 para propuestas). Sin datos personales completos en el registro |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit. **Prerrequisito:** plan 006 F0 y F1, y plan 009 F0 a F2 (DA-7).

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Cerrar las DA; spike de DA-8 (enviar desde `FormRenderer` incrustado en Filament); enum `SubmissionOrigin`; permiso `submissions.invite` en `RolePermissions` con su prueba de rol; actualizar glosario y `docs/privacidad.md`; propuesta de datos aprobada. Sin cambio de comportamiento | S |
| F1 | Datos de H-018-1 (columnas y `CHECK` sobre `submissions`); `RegisterInvitedSpeaker`; página de alta con tipo, formulario y `FormRenderer`; pruebas rojas primero | M |
| F2 | Vista «Invitados» y filtro de origen en «Propuestas» (H-018-2); ajustes de los planes 009 y 011 | M |
| F3 | Detalle con marca de invitado (H-018-3) | S |
| F4 | Editar y retirar invitados (H-018-4, «Debería») y archivos de invitados con `call_id` opcional (DA-5) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-018-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**H-018-1 · Dar de alta un invitado** (M, depende de H-006-1 y H-005-3)

- **CA-018-1:** *Dado* un organizador de la organización A, un evento de A con un formulario publicado y un tipo activo, *cuando* da de alta a un invitado con tipo, formulario, un participante principal y respuestas válidas, *entonces* se crea una propuesta con `origin = invited`, estado `accepted`, sin convocatoria, con `registered_by_user_id` del organizador y `authorization_declared_at` guardados.
- **CA-018-2:** *Dado* el alta, *cuando* termina, *entonces* la propuesta guarda `form_version` y `form_snapshot` del formulario con que se capturó y un cambio posterior del formulario no altera lo ya guardado.
- **CA-018-3:** *Dado* un alta, *cuando* las respuestas no cumplen el formulario (obligatorio vacío, opción inexistente, clave desconocida, campo retirado), *entonces* se rechaza con el mismo error por campo que una propuesta y no se crea nada.
- **CA-018-4:** *Dado* el formulario de alta, *cuando* el organizador elige el tipo, *entonces* se ofrece como formulario sugerido el de la convocatoria publicada más reciente de ese tipo, y puede cambiarlo por otro formulario publicado del mismo evento.
- **CA-018-5:** *Dado* un tipo archivado, un tipo de otro evento o de otra organización, *cuando* se intenta usar, *entonces* se rechaza; igual con un formulario sin publicar (`FORM_NOT_PUBLISHED`) o de otro evento u organización.
- **CA-018-6:** *Dado* un alta, *cuando* los participantes son menos de 1 o más de 5, *entonces* se rechaza; con 1 a 5 se acepta y uno solo es el principal.
- **CA-018-7:** *Dado* un participante cuyo correo ya existe como `Contact` de la organización, *cuando* se da de alta, *entonces* se enlaza a ese `Contact` sin sobrescribir su ficha (como CA-006-10); si no existe, se crea.
- **CA-018-8:** *Dado* un evento sin convocatoria o con la convocatoria cerrada, *cuando* el organizador da de alta un invitado, *entonces* se permite: el alta no depende de fechas ni de estado de convocatoria.
- **CA-018-9:** *Dado* el cuerpo de la petición, *cuando* trae `organization_id`, `event_id`, `origin`, `status` o `registered_by_user_id`, *entonces* se ignoran o se rechazan; los valores salen del contexto y de la sesión.
- **CA-018-10:** *Dado* el organizador sin marcar la declaración de autorización del invitado, *cuando* intenta guardar, *entonces* se rechaza por validación.
- **CA-018-11:** *Dado* un doble envío del mismo alta (doble clic o reintento), *cuando* llegan dos peticiones con la misma clave de idempotencia, *entonces* existe una sola propuesta.
- **CA-018-12:** *Dado* un formulario con un campo de archivo obligatorio, *cuando* aún no existe F4, *entonces* el alta responde con un error claro de «archivos de invitados no disponibles» y no crea nada.

**H-018-2 · Vistas separadas** (M, depende de H-009-1)

- **CA-018-13:** *Dado* un evento con propuestas de convocatoria e invitados, *cuando* el organizador abre «Propuestas», *entonces* ve solo las de origen `call`; *cuando* abre «Invitados», *entonces* ve solo las de origen `invited`.
- **CA-018-14:** *Dado* la lista de invitados, *cuando* se filtra por tipo, eje, estado y rango de fechas, *entonces* se aplican igual que en propuestas (plan 009), con paginación y máximo de 100 por página.
- **CA-018-15:** *Dado* un invitado, *cuando* se consulta la lista de «Propuestas», *entonces* no aparece, y un invitado nunca se cuenta en el resumen de propuestas del plan 009.
- **CA-018-16:** *Dado* la navegación del evento, *cuando* el usuario tiene `submissions.view`, *entonces* ve las dos entradas por separado; sin ese permiso no ve ninguna y la ruta responde 403.
- **CA-018-17:** *Dado* un evento de la organización B, *cuando* el organizador de A abre las listas por URL, *entonces* responde 404.
- **CA-018-18:** *Dado* la lista de invitados sin registros, *cuando* se abre, *entonces* muestra el estado vacío con la acción «Dar de alta» solo si el usuario tiene `submissions.invite`.
  Manual: abrir «Invitados» en un evento sin invitados a ~375 px y ~1280 px, con y sin permiso, y revisar la consola.

**H-018-3 · Detalle del invitado** (S, depende de H-009-2)

- **CA-018-19:** *Dado* un invitado, *cuando* el organizador abre su detalle, *entonces* ve la marca «Invitado», el tipo, el eje, el estado, quién lo dio de alta y cuándo, los participantes y las respuestas con las etiquetas de la versión del formulario con que se capturaron (incluidos los campos que luego se retiraron).
- **CA-018-20:** *Dado* un invitado de otra organización o de otro evento, *cuando* se abre su detalle por identificador, *entonces* responde 404.
- **CA-018-21:** *Dado* un usuario sin `submissions.view`, *cuando* abre el detalle, *entonces* responde 403.
- **CA-018-22:** *Dado* cada apertura del detalle, *cuando* ocurre, *entonces* queda un registro de auditoría con quién y cuándo, sin datos personales completos.
- **CA-018-23:** *Dado* el detalle con archivos (F4), *cuando* el usuario tiene `files.download`, *entonces* ve la descarga; sin él no la ve y la ruta responde 403.
  Manual: abrir el detalle a ~375 px y ~1280 px y comprobar que respuestas y participantes no se desbordan.

**H-018-4 · Editar y retirar** (S, «Debería»)

- **CA-018-24:** *Dado* un invitado, *cuando* un organizador con `submissions.invite` edita título, eje, participantes o respuestas, *entonces* se validan igual que en el alta, se guarda con la versión del formulario original y queda auditado el valor anterior y el nuevo.
- **CA-018-25:** *Dado* un invitado, *cuando* se retira, *entonces* pasa a `withdrawn`, sigue visible en «Invitados» con ese estado y no se borra; retirar uno ya retirado no cambia nada.
- **CA-018-26:** *Dado* un miembro sin `submissions.invite`, *cuando* intenta editar o retirar, *entonces* se deniega (403) y el botón no se muestra.
- **CA-018-27:** *Dado* dos organizadores que editan el mismo invitado a la vez, *cuando* el segundo guarda con una versión vencida, *entonces* se rechaza con conflicto y no pisa el cambio del primero.

**Transversales**

- **CA-018-28:** *Dado* un invitado, *cuando* se asignan evaluadores, se decide en bloque o se publican resultados (planes 011 y 012), *entonces* el invitado queda excluido y no recibe ningún correo de resultado.
- **CA-018-29:** *Dado* la base de datos, *cuando* se inserta un invitado con `organization_id` de A y un evento o tipo de B, *entonces* la clave foránea compuesta lo rechaza; y *cuando* se inserta con `origin = invited` y una convocatoria, o con `origin = call` sin convocatoria, *entonces* el `CHECK` lo rechaza.
- **CA-018-30:** *Dado* el modelo `Submission` ampliado, *cuando* corren las pruebas de aislamiento (`ModelsAreScopedTest`, `TenantIsolationTest`), *entonces* cubren las propuestas de origen `invited`.
- **CA-018-31:** *Dado* un archivo de un invitado (F4), *cuando* se sube, *entonces* se aplican la validación por contenido, el disco privado y la ruta con organización del plan 007, y un archivo de otra organización responde 404.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| El plan 006 ya está aprobado y puede empezar a construirse sin las columnas de este plan | DA-7: coordinar con Gabriel; si 006 F0 ya escribió su migración, las columnas van en una migración propia de este plan |
| Datos personales de alguien que aún no ha visto el aviso de privacidad | Declaración del organizador registrada (DA-4), auditoría y acceso restringido; el aviso se entrega en R2 |
| Un invitado se cuela en evaluación, resultados o conteos y recibe un correo equivocado | CA-018-15 y CA-018-28; filtro de origen en un único lugar de consulta y cobertura en los planes 009, 011 y 012 |
| Relajar `call_for_submissions_id` y el consentimiento debilita las restricciones de `submissions` | `CHECK` que liga cada origen con sus columnas obligatorias (CA-018-29) |
| Duplicar a una misma persona como autor y como invitado | Se enlaza el `Contact` por correo (CA-018-7); la fusión de duplicados queda fuera |
| Enviar el formulario desde un `FormRenderer` incrustado en Filament puede no ser viable | Spike en F0; respaldo: formulario de Filament generado, con deuda registrada |
| Cambiar el tipo de la convocatoria (`submission_type_id`) tras la copia deja el tipo de la propuesta desfasado | El tipo de una propuesta no cambia con el de la convocatoria; documentado en el plan 006 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
