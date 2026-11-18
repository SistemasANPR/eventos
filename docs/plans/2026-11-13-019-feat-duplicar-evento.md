# feat: duplicar un evento (E-18)

- **Fecha:** 2026-11-13
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Cada edición de un evento repite casi toda su configuración: tipos de propuesta, ejes, formularios, convocatorias, aviso de privacidad y plantillas de correo. Hoy el organizador la captura desde cero. Este plan permite **duplicar un evento sin datos de personas**: se elige un evento, se indica nombre, identificador y fechas de la nueva edición, y se obtiene un evento en borrador con la configuración copiada, listo para revisar y publicar. Es la épica E-18 de R2 («un evento nuevo en minutos»).

Para quién: el equipo organizador (administrador y organizador). Se duplica dentro de la misma organización; nunca entre organizaciones.

**Dentro:**
- Acción «Duplicar» sobre un evento, con un formulario de nombre, identificador, fechas y zona horaria.
- Copia de la configuración: datos del evento, tipos de propuesta, ejes, formularios, convocatorias, aviso de privacidad y plantillas de correo propias del evento.
- Fechas de las convocatorias desplazadas según las del nuevo evento; todo lo copiado nace en borrador.
- Atomicidad: o se copia todo o no se crea nada.
- Auditoría de la duplicación y pruebas de que no se copia ningún dato de personas.

**Fuera:**
- Una entidad «plantilla de evento» (DA-1): cualquier evento sirve de plantilla.
- Activar o desactivar módulos por evento (la segunda historia de E-18, DA-2): se difiere hasta que exista un segundo módulo opcional.
- Rúbrica, boletos, programa y demás módulos que aún no existen: cada uno añade su paso de copia cuando se construya (DA-7).
- Duplicar entre organizaciones o importar la configuración de otra organización.
- Copiar propuestas, respuestas, archivos, contactos, equipo, resultados, evaluaciones o la bitácora.

## 2. Punto de partida

Inspeccionado el 2026-11-13 en `staging`. Hechos, no suposiciones:

- **Evento:** `events` (`database/migrations/2026_11_10_100000_create_events_table.php`) con nombre y descripción traducibles, `slug` único por organización, tipo, `starts_at`/`ends_at` en UTC con zona horaria, sede, idiomas, idioma base, moneda y estado `draft`/`published`/`archived`. No hay columnas de configuración por evento ni de «módulos».
- **`CreateEvent`** (`app/Actions/Events/CreateEvent.php:36`): autoriza con `events.manage`, valida, crea el evento en borrador, siembra los tipos de propuesta por defecto (`SeedDefaultSubmissionTypes`, idempotente: no hace nada si el evento ya tiene tipos) y audita. **No** crea aviso, ejes, convocatoria ni formulario.
- **Tablas con `event_id`** (todas con clave foránea compuesta `(event_id, organization_id)`): `tracks`, `submission_types`, `call_for_submissions`, `form_definitions`, `privacy_notices`, `mail_templates` (con `event_id` opcional: nulo es de la organización) y `submission_files`. `notification_settings` es solo de la organización. **No existen** `submissions`, `event_assignments`, rúbricas ni tablas de equipo por evento.
- **Convocatorias:** `slug` único por evento, `submission_type_id` obligatorio, fechas `opens_at < closes_at` obligatorias, `results_at` opcional, `form_definition_id` nulo hasta que se publica un formulario en ella (`PublishFormDefinition`).
- **Aviso de privacidad:** las versiones publicadas son inmutables (disparador en la base) y registran quién y cuándo publicó; solo puede haber un borrador por evento. `PublishCall` rechaza publicar una convocatoria si el evento no tiene aviso publicado en cada idioma de la convocatoria (`PRIVACY_NOTICE_MISSING`).
- **Duplicado de formularios ya existente** (`DuplicateFormDefinition`, plan 005): copia dentro del mismo evento, como borrador, sin campos retirados y con sufijo «(copia)»; delega en `CreateFormDefinition`, que valida contra los idiomas del evento de destino y normaliza claves.
- **Filament:** `app/Filament/Resources/Events/` con `LifecycleActions` (publicar y archivar), usado en la tabla, la edición y la vista; `EventPolicy` con `create` (`events.manage`) y `publish`/`archive` (`events.publish`).
- **Plan de desarrollo (E-18, §10.3):** «duplicar un evento sin datos de personas… se copia la configuración y ninguna propuesta ni persona» y «activar o desactivar módulos por evento».

## 3. Decisiones

Resumen: ninguna está cerrada. Cada una lleva mi recomendación.

### DA-1 · Plantillas de evento — se recomienda **no crear una entidad «plantilla»; cualquier evento se duplica**

Duplicar un evento ya cumple «un evento nuevo en minutos». Una plantilla propia (evento marcado, con su lista) es otra entidad que mantener y no la pide ninguna historia. Si luego hace falta, bastará una marca sobre el evento.

### DA-2 · Módulos por evento — se recomienda **diferirlos**

Hoy el único módulo opcional sería la convocatoria; hospedaje, boletos o programa no existen. Un mecanismo de módulos construido sin un segundo módulo real sería una abstracción anticipada (ADR-0001). Se especifica cuando llegue el primero de R3 y se registra en el plan de desarrollo.

### DA-3 · Qué se copia — se recomienda **una lista cerrada de configuración**

Se copia: datos del evento (tipo, sede, ciudad, idiomas, idioma base, moneda, descripción), tipos de propuesta y ejes **no archivados**, formularios, convocatorias, aviso de privacidad (como borrador) y plantillas de correo con `event_id`. Todo lo demás queda fuera, en particular cualquier cosa que sea o pueda contener datos de personas. Una lista cerrada es más segura que una lista de exclusiones: lo que se añada al sistema no se copia hasta que alguien lo decida.

### DA-4 · Fechas — se recomienda **fechas nuevas del evento y convocatorias desplazadas**

La persona indica el inicio y el fin del nuevo evento. Las fechas de apertura, cierre y resultados de cada convocatoria se desplazan por la diferencia entre el inicio del evento origen y el del nuevo, y todas nacen en borrador para que se revisen. Alternativa: dejarlas vacías, que la base no permite (`opens_at` y `closes_at` son obligatorias).

### DA-5 · Formularios y convocatorias — se recomienda **formularios como borrador sin enlazar a la convocatoria**

Cada formulario se copia como borrador, versión 1, sin campos retirados (igual que CA-005-36). Las convocatorias copiadas quedan con `form_definition_id` nulo: la persona revisa y publica cada formulario en su convocatoria con el flujo ya existente. Es un paso más por convocatoria, pero evita publicar sin revisar un texto que cambia de edición en edición.

### DA-6 · Aviso de privacidad — se recomienda **copiar el texto vigente como borrador de la versión 1, nunca una versión publicada**

Las versiones publicadas son evidencia de lo que aceptó cada persona y son inmutables; copiarlas fabricaría un «quién y cuándo publicó» falso. Se copia el cuerpo de la versión publicada más reciente (o del borrador si no hay publicada) como borrador. La persona lo publica; hasta entonces no puede publicar convocatorias (`PRIVACY_NOTICE_MISSING`).

### DA-7 · Cómo se extiende la copia — se recomienda **lista fija dentro de `DuplicateEvent`, un paso por módulo**

Cada módulo futuro (rúbrica del plan 011, boletos y programa de R3) añade su paso y su criterio cuando se construya, llamando a su propia Action. Alternativa: un contrato que cada módulo registra. Es más flexible, pero es una abstracción sin un segundo usuario todavía.

### DA-8 · Permiso — se recomienda **sin permiso nuevo**

Duplicar crea un evento, así que exige `events.manage` (política `create`) y poder ver el evento origen. Como además usa las Actions de ejes, formularios y convocatorias, comprueba de entrada `tracks.manage`, `forms.manage` y `calls.manage`; falta cualquiera y se rechaza antes de copiar nada. Administrador y organizador tienen los cuatro.

### DA-9 · Nombre e identificador — se recomienda **nombre prellenado con «(copia)» e identificador propuesto, editable**

El nombre se prellena con el del origen y el sufijo «(copia)» por idioma; el identificador se propone a partir del nombre y es único por organización. Si ya existe, se rechaza con error por campo; no se renombra solo, porque cambiaría una URL pública sin que la persona lo vea.

## 4. Contrato

**Rutas.** Sin rutas JSON ni webhooks nuevos. La acción vive en Filament sobre el recurso de eventos.

**Datos.** Sin migraciones ni datos persistentes nuevos: se crean filas en tablas existentes con sus Actions. La duplicación queda en la bitácora (`activity_log`, evento `event_duplicated`).

**Action y entrada.**
- `DuplicateEvent::handle(User $actor, Event $source, array $data): Event`, en `app/Actions/Events/`. `$data` trae `name` (por idioma), `slug`, `starts_at`, `ends_at` (hora local de `timezone`) y `timezone`; se validan con las mismas reglas de `StoreEventRequest`.
- Form Request propio (`DuplicateEventRequest`) que reutiliza `StoreEventRequest::rulesFor` y añade que `source` exista en la organización.
- **Reutiliza las Actions existentes** del módulo dueño de cada dato (`CreateEvent`, de ejes, tipos, formularios y convocatorias). Para copiar formularios hacia otro evento, `DuplicateFormDefinition` se amplía con un evento de destino opcional (hoy copia siempre en el mismo evento).
- Todo dentro de una sola transacción.

**Pantalla.** Acción «Duplicar» en la tabla de eventos, en la vista y en la edición, junto a Publicar y Archivar (`LifecycleActions`), con un modal de los cinco campos. Al terminar redirige al nuevo evento y muestra el resumen de lo copiado.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador y organizador: duplicar (`events.manage` más `tracks.manage`, `forms.manage` y `calls.manage`, comprobados de entrada). Miembro, evaluador y visitante: nada |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `EventPolicy::create` y `view`, y las políticas de ejes, formularios y convocatorias a través de sus Actions; `tests/Feature/Events/DuplicateEventTest.php` con permitido y denegado por rol |
| ¿Hay modelos nuevos con datos de una organización? | No. Se crean filas de modelos que ya usan `BelongsToOrganization`; se extienden `TenantIsolationTest` y la prueba de inserción cruzada con la duplicación |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Cruza dos eventos de la misma organización. Un evento origen de otra organización responde 404. `event_assignments` no existe todavía |
| ¿Se guardan datos personales nuevos? | No. La duplicación **excluye** todo dato de personas (propuestas, participantes, archivos, contactos, equipo, evaluaciones, resultados, envíos de correo). Quedan copiados textos de configuración de la organización; `from_name` y `reply_to` de las plantillas de correo pueden ser un correo de contacto de la organización, que ya era suyo |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No; la moneda del evento se copia como configuración |
| ¿Se envían correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | `event_duplicated`: quién, cuándo, evento origen, evento nuevo y cuántos elementos de cada tipo se copiaron, sin textos ni datos personales. La bitácora del origen no se copia |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit. **Prerrequisito:** plan 005 terminado (ya lo está) y los planes 003 y 004 en `Terminado`.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Cerrar las DA; ampliar `DuplicateFormDefinition` con evento de destino (sin cambiar su comportamiento actual); pruebas rojas primero | S |
| F1 | `DuplicateEvent` con el evento, los tipos y los ejes; atomicidad, aislamiento y auditoría | M |
| F2 | Formularios, convocatorias con fechas desplazadas, aviso de privacidad como borrador y plantillas de correo del evento | M |
| F3 | Acción «Duplicar» en Filament con su modal, resumen y verificación manual a ~375 px y ~1280 px | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-019-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**H-019-1 · Duplicar un evento sin datos de personas** (M, depende de H-003-1, H-004-1 y H-005-1)

- **CA-019-1:** *Dado* un administrador de la organización A y un evento de A, *cuando* lo duplica con nombre, identificador, fechas y zona horaria válidos, *entonces* se crea un evento nuevo en `draft`, de la misma organización, con el tipo, la sede, la ciudad, los idiomas, el idioma base, la moneda y la descripción del origen y con las fechas indicadas guardadas en UTC.
- **CA-019-2:** *Dado* el formulario de duplicar, *cuando* el identificador ya existe en la organización, es inválido, o el fin es anterior al inicio, *entonces* se rechaza con error por campo y no se crea nada; el mismo identificador en otra organización se permite.
- **CA-019-3:** *Dado* un evento origen con tipos de propuesta y ejes, algunos archivados, *cuando* se duplica, *entonces* el nuevo evento tiene copias de los **no archivados**, con nombre y descripción en todos sus idiomas, en el mismo orden, y no tiene los archivados; no se siembran además los tipos por defecto.
- **CA-019-4:** *Dado* un evento origen con formularios en borrador y publicados, *cuando* se duplica, *entonces* el nuevo evento tiene una copia de cada uno como borrador, versión 1, sin campos retirados y sin `published_at`, y el origen no cambia.
- **CA-019-5:** *Dado* un evento origen con convocatorias en cualquier estado, *cuando* se duplica, *entonces* el nuevo evento tiene una copia de cada una en `draft`, con el tipo remapeado al tipo copiado, `form_definition_id` nulo y un identificador generado desde el título como en CA-004-28.
- **CA-019-6:** *Dado* un origen que empieza el 10-mar-2026 con una convocatoria que abre el 10-ene y cierra el 15-feb-2026 y un nuevo evento que empieza el 10-mar-2027, *cuando* se duplica, *entonces* la copia abre el 10-ene-2027 y cierra el 15-feb-2027, y los resultados, si existían, se desplazan igual; con una diferencia que invalide el orden de fechas se rechaza el cambio de fechas completo.
- **CA-019-7:** *Dado* un evento origen con aviso de privacidad publicado, *cuando* se duplica, *entonces* el nuevo evento tiene el texto como **borrador** de la versión 1, sin `published_at` ni `published_by`, y ninguna versión publicada del origen se copia.
- **CA-019-8:** *Dado* un evento duplicado cuyo aviso copiado aún es borrador, *cuando* se intenta publicar una de sus convocatorias, *entonces* se rechaza con `PRIVACY_NOTICE_MISSING` hasta que el aviso se publique.
- **CA-019-9:** *Dado* un evento origen con plantillas de correo propias del evento, *cuando* se duplica, *entonces* se copian al nuevo evento con la persona que duplica como autora, y las plantillas de la organización no se duplican.
- **CA-019-10:** *Dado* un evento origen con cualquier dato de personas (propuestas, participantes, archivos, contactos, equipo, evaluaciones, resultados, envíos de correo, bitácora), *cuando* se duplica, *entonces* el nuevo evento no contiene ninguno de ellos.
- **CA-019-11:** *Dado* que falla cualquier paso de la copia, *cuando* se duplica, *entonces* no queda creado ningún evento ni elemento copiado, y el error llega a la persona.
- **CA-019-12:** *Dado* un doble envío del mismo formulario, *cuando* llegan dos peticiones con el mismo identificador, *entonces* existe un solo evento nuevo y la segunda responde con el error del identificador repetido.
- **CA-019-13:** *Dado* un origen `published`, `archived` o `draft`, *cuando* se duplica, *entonces* se permite en los tres casos, y un evento eliminado no se puede duplicar.

**Permisos y aislamiento**

- **CA-019-14:** *Dado* un miembro sin `events.manage`, o un organizador al que le falta `tracks.manage`, `forms.manage` o `calls.manage`, *cuando* intenta duplicar, *entonces* se deniega (403) antes de copiar nada y el botón no se muestra.
- **CA-019-15:** *Dado* un administrador de A, *cuando* intenta duplicar por identificador un evento de la organización B, *entonces* la respuesta es 404 y no se crea nada.
- **CA-019-16:** *Dado* la base de datos, *cuando* se intenta insertar una copia con el `organization_id` de A y el `event_id` de un evento de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada sobre cada tabla copiada).
- **CA-019-17:** *Dado* un evento duplicado, *cuando* una persona de la organización B lista sus datos, *entonces* no ve ninguno de los elementos copiados.
- **CA-019-18:** *Dado* una duplicación, *cuando* termina, *entonces* queda un registro `event_duplicated` con quién, cuándo, origen, nuevo evento y el número de elementos copiados por tipo, sin textos ni datos personales.

**Pantalla**

- **CA-019-19:** *Dado* la lista de eventos, *cuando* un usuario con permisos abre el menú de un evento, *entonces* ve «Duplicar», con un modal prellenado (nombre con «(copia)», identificador propuesto, zona horaria y moneda del origen) que valida por campo.
  Manual: duplicar un evento con convocatoria, formulario, ejes y aviso desde la lista, la vista y la edición, a ~375 px y ~1280 px y con la consola abierta.
- **CA-019-20:** *Dado* una duplicación correcta, *cuando* termina, *entonces* se redirige al nuevo evento y se muestra cuánto se copió de cada tipo y qué falta por publicar (aviso y formularios).
  Manual: comprobar el mensaje de resumen a ~375 px y ~1280 px.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Copiar por descuido datos de personas cuando se añadan tablas nuevas | Lista cerrada (DA-3) y CA-019-10, que enumera lo que no debe copiarse; cada módulo nuevo añade su paso y su prueba |
| El aviso de privacidad copiado se publica sin revisión | Se copia como borrador y bloquea la publicación de convocatorias hasta publicarlo (CA-019-7 y CA-019-8) |
| Las fechas desplazadas dan una convocatoria que abre en el pasado o en un día equivocado | Todo queda en borrador y se rechaza el desplazamiento que rompa el orden de fechas; la persona las revisa antes de publicar |
| Una falla a la mitad deja un evento incompleto | Una sola transacción y CA-019-11 |
| El plan 006 añade columnas a la convocatoria (`min_participants` y `max_participants`) que el copiado no conoce | Al construir el plan 006 se añaden a la copia de convocatorias y a CA-019-5 |
| Duplicar muchas veces genera eventos de prueba huérfanos | El evento nuevo nace en borrador y se puede archivar; sin proceso automático de limpieza en este plan |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
