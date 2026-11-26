# feat: Lugares del evento (salas y externos) y cupo de las actividades

> Plan 035. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 035: todos sus criterios son `CA-035-n`. Amplía las salas del plan 031 para que el programa también cubra actividades **fuera del recinto** (un parque, un espacio público de la ciudad sede) y actividades **con cupo** (en la ANPR, los talleres), sin crear un módulo aparte.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-26
- **Estado:** Aprobado (2026-11-26: Antonio aprueba el plan y sus recomendaciones; DA-1 a DA-13 cerradas)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna abierta. Resuelve en parte la deuda «sedes y salas compartidas» del plan 031 §10 (se queda sin sedes) y la de «mostrar la sala en el sitio público». Prepara E-27 «Talleres con cupo» (`docs/plan-de-desarrollo.md`), que añadirá la inscripción y la lista de espera sobre el cupo que guarda este plan.

## 1. Objetivo y alcance

En el congreso de la ANPR hay talleres: actividades que ocurren en una sala del recinto o en otro lugar de la ciudad (un parque, un espacio público), con **cupo**. No todas las actividades lo tienen. Hoy el programa solo conoce salas del recinto (plan 031, F2 y F3), sin capacidad, y no sabe nada de cupos ni de lugares externos con su punto de reunión, su transporte o sus responsables. Otras organizaciones llamarán a estas actividades de otro modo, o no las tendrán.

La capacidad de un lugar, el cupo de una actividad y los datos de un lugar externo son **tres cosas distintas**: la capacidad es física y es del lugar; el cupo lo decide la organización y es de la actividad; el punto de reunión, el transporte y los responsables cambian de una actividad a otra aunque el parque sea el mismo.

**Dentro:**
- **Lugares** (la sala del plan 031 con un tipo): `sala` (dentro del recinto, de uso exclusivo) o `externo` (de uso simultáneo). Con capacidad, dirección o ubicación y enlace de mapa, todos opcionales. Para el organizador la página se llama «Lugares».
- **Cupo** de la actividad: número opcional; vacío es «sin cupo». Informativo: no hay inscripción ni lista de espera en este plan.
- **Datos de un lugar externo en la actividad:** punto de reunión, transporte (con su detalle) y hasta cinco responsables con nombre, teléfono y correo, cada uno con su marca «visible al público» (apagada por defecto).
- **Formulario que se adapta:** al elegir «Lugar externo» aparecen los datos del lugar externo; el cupo es un interruptor aparte que sirve a cualquier actividad.
- **Sitio público:** en el programa solo la insignia «Cupo limitado» (sin números); en el detalle de la actividad, el cupo, el lugar, el punto de reunión, el transporte, los responsables públicos y, si el organizador lo decide, la capacidad del lugar.
- Reglas de choque: los lugares externos admiten actividades simultáneas; las salas siguen siendo exclusivas.
- Datos personales de los responsables dentro del inventario de privacidad.

**Fuera:**
- Inscripción a las actividades, lista de espera, control de cupos ocupados y botón de inscribirse (E-22 y E-27). El cupo solo se muestra.
- Sedes y salas compartidas entre eventos (plan 031 §10). Un lugar es siempre de un evento.
- Categorías de lugares: el tipo `sala` o `externo` cubre la necesidad.
- Capacidad por montaje (teatro, escuela, banquete), equipamiento y reservas de espacios.
- Que una categoría de actividad preconfigure el cupo o el lugar. Cada actividad se configura por sí misma.
- La agenda visual (plan 031, F4 y F5): este plan solo fija cómo se comportan los lugares externos en ella (§3, DA-9).

## 2. Punto de partida

Medido el 2026-11-26 en `staging` (`a4f4b08`) y en la rama `feat/plan-031-f3-sala-y-choques` (`ed31d1d`, sin fusionar):

- `rooms` (plan 031, F2): `name`, `position`, ámbito evento; sin capacidad, tipo, dirección ni mapa. Página «Salas» (`ManageEventRooms`), `RoomPolicy` con `activities.manage` y `activities.delete`, Actions `CreateRoom`, `UpdateRoom`, `DeleteRoom` y `MoveRoom`.
- `event_activities.room_id` (nulo, clave compuesta, `CHECK` de «sala solo si está programada»). La F3 añadió `DetectScheduleConflicts`, `ActivityRoomConflict` y los avisos de ponente repetido; en `CreateActivity` y `UpdateActivity` el evento se bloquea antes de comprobar la sala. **Toda sala es exclusiva.**
- No existen cupo, lugar externo, punto de reunión, transporte ni responsables. No hay modelos de asistente, inscripción ni boleto (`app/Models`); el glosario define `Taller` (`Workshop`) como «sesión con cupo e inscripción» y E-27 está en R4, detrás de E-22.
- Público (plan 029): constructores `PublicActivityData` y `PublicSpeakerData` con **listas cerradas de claves**; `CA-029-7` y `CA-031-28` comparan las claves exactas, y `CA-031-28` exige que ninguna cadena mencione la sala. El borrador del plan 033 (app móvil y API) dice que `/events/{event}/rooms` y `activities` incluyen la sala: hoy el borrador y la regla del plan 031 se contradicen.
- Privacidad: `PersonalDataInventoryTest` exige que todo modelo de `app/Models` figure como cubierto por la anonimización o exento con su razón, y que cada columna de una tabla cubierta esté clasificada.
- Frontend: `ActivityCard` (molécula), la página `Events/Activity` y los tipos de `resources/js/types/program.ts` reflejan el contrato público actual.

## 3. Decisiones

Resumen: un solo catálogo de lugares con tipo; el cupo y los datos del lugar externo viven en la actividad; lo público muestra poco en el programa y el detalle en la ficha. DA-5 a DA-8 las contestó Antonio el 2026-11-26 con sus respuestas; el resto las aprobó el mismo día con la recomendación del plan.

### DA-1 · Cómo se modela el lugar — se recomienda **una sola tabla con tipo (`rooms.kind`)**

Opciones: (a) la tabla `rooms` con una columna `kind` (`room` o `external`); (b) un modelo aparte para los lugares externos. Con (a) el selector, el catálogo, el orden y la agenda tratan un solo concepto y un parque se elige igual que un auditorio; con (b) habría dos catálogos y dos selectores para lo mismo. Recomendación (a). **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-2 · Nombre interno y de interfaz — se recomienda **mantener `Room` y `rooms` por dentro y llamar «Lugares» al módulo en la interfaz**

Renombrar a `Place` mueve el código y las pruebas de las F2 y F3 sin dar nada al usuario. El glosario aclara que `Room` es «lugar del evento, sala o externo». Si más adelante confunde, se renombra con una migración propia. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-3 · Dónde vive el cupo — se recomienda **en la actividad, opcional, informativo**

`event_activities.quota` (entero, nulo = sin cupo). Es independiente del lugar: también hay talleres con cupo dentro de una sala. No se hace cumplir (no hay inscripción); E-27 construirá la inscripción sobre este dato. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-4 · Dónde viven el punto de reunión, el transporte y los responsables — se recomienda **en la actividad, y solo si su lugar es externo**

Cambian de una actividad a otra aunque el lugar sea el mismo (el taller de las 9:00 se reúne en la puerta norte y el de las 12:00 en el quiosco). Solo se aceptan cuando el lugar de la actividad es externo; si la actividad pasa a una sala o a «sin lugar», se borran en la misma transacción. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-5 · Visibilidad de los responsables — **Cerrada el 2026-11-26: por defecto solo el equipo, con opción de hacerlo visible al público**

Cada responsable lleva `is_public` (falso por defecto). El formulario avisa que, al marcarlo, la persona debe haber dado su permiso. Solo los marcados salen en el detalle público.

### DA-6 · Capacidad del lugar en el público — **Cerrada el 2026-11-26: la decide el organizador**

`rooms.capacity_is_public` (falso por defecto). El cupo de la actividad, en cambio, sí se muestra en el detalle cuando existe, porque así lo pidió la ANPR. En el programa solo se muestra la insignia «Cupo limitado»; nunca un número.

### DA-7 · Categorías de lugares — **Cerrada el 2026-11-26: sin categorías**

El tipo `sala` o `externo` basta. No se crea catálogo de categorías de lugares.

### DA-8 · Dirección y mapa — **Cerrada el 2026-11-26: opcionales y para cualquier lugar**

Si no hay información, no se muestra. El campo trae texto de ayuda que orienta al organizador (edificio, piso, ala o entrada; en un lugar externo, calle y colonia o una referencia). El mapa es un enlace `https` opcional.

### DA-9 · Choques y agenda con lugares externos — se recomienda **los externos no se bloquean entre sí; en la agenda van en una columna «Fuera de sede»**

Varios talleres a la vez en el mismo parque es lo normal, así que `DetectScheduleConflicts` ignora los lugares externos para el choque de sala. El aviso de **ponente en dos actividades a la vez** se mantiene también ahí (una persona no está en dos sitios). La agenda de la F4 del plan 031 pondrá todas las actividades externas en una columna «Fuera de sede» que admite actividades simultáneas. Este plan debe fusionarse **antes** de esa F4 para diseñarla una sola vez. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-10 · Cambiar el tipo de un lugar en uso — se recomienda **bloquearlo con un error que diga cuántas actividades lo usan**

Pasar de externo a sala dejaría actividades con datos de lugar externo; pasar de sala a externo cambia la regla de choque bajo actividades ya programadas. Se bloquea igual que eliminar un lugar en uso (plan 031, DA-13); hay que mover las actividades antes. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-11 · Responsables: tabla propia — se recomienda **`activity_contacts` en vez de un texto libre**

Opciones: (a) tabla con nombre, teléfono, correo, orden e `is_public`; (b) un campo de texto «contacto». Con (a) la marca `is_public` es por persona y la privacidad puede localizarlos y borrarlos; con (b) un teléfono pegado en texto libre es dato personal imposible de localizar. Recomendación (a), máximo 5 por actividad. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-12 · Orden de trabajo — se recomienda **fusionar la F3 del plan 031, luego este plan y después la F4 del plan 031**

Este plan modifica `DetectScheduleConflicts`, `CreateActivity` y `UpdateActivity` de la F3, y la agenda de la F4 necesita saber qué es un lugar externo. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

### DA-13 · El sitio público muestra el lugar — se recomienda **sí, en el detalle de la actividad (no en el programa)**

Resuelve la contradicción entre el plan 031 (la sala no sale en lo público) y el borrador del plan 033 (la API incluye la sala). El programa no muestra el lugar; el detalle sí, con nombre y, si existen, dirección y mapa. Esto **cambia a propósito** el contrato del plan 029 y las pruebas `CA-029-7` y `CA-031-28`. **Cerrada el 2026-11-26: Antonio aprueba la recomendación.**

## 4. Contrato

### 4.1 Datos (propuesta para `docs/database-design.md` §6, a escribir en F0 y aprobar con el plan)

Las migraciones **no se escriben ni se aplican a una base real** sin la propuesta aprobada (acción 1 de `AGENTS.md`). Cambios aditivos y reversibles.

- **`rooms`** (columnas nuevas): `kind varchar(16)` `NOT NULL` por defecto `'room'` con `CHECK (kind IN ('room','external'))`; `capacity integer` nula con `CHECK (capacity IS NULL OR capacity > 0)`; `capacity_is_public boolean` `NOT NULL` por defecto `false`; `address varchar(255)` nula; `map_url varchar(2048)` nula. Las salas existentes quedan como `room`, sin capacidad ni dirección.
- **`event_activities`** (columnas nuevas): `quota integer` nula con `CHECK (quota IS NULL OR quota > 0)`; `meeting_point varchar(500)` nula; `transport_info varchar(500)` nula (no nula significa «incluye transporte»). Sin cambios al `room_id` ni a su `CHECK`.
- **`activity_contacts`** (tabla nueva, ámbito evento): `id uuid`, `organization_id`, `event_id`, `activity_id`, `name varchar(120)`, `phone varchar(40)` nulo, `email varchar(254)` nulo, `position smallint`, `is_public boolean` por defecto `false`, `timestamps`. Clave foránea compuesta `(activity_id, event_id, organization_id)` hacia `event_activities` con borrado en cascada, clave foránea compuesta `(event_id, organization_id)` hacia `events`, trigger de organización inmutable como en `rooms`, único `(activity_id, position)`.
- **Integridad que no se puede dejar al formulario:** que solo un lugar externo admita punto de reunión, transporte y responsables se comprueba en las Actions con el evento bloqueado (como el choque de sala); la base garantiza lo demás (valores positivos, tipo válido, claves compuestas).
- **Alternativa descartada:** un campo de texto libre para los responsables (DA-11) y un modelo aparte para los lugares externos (DA-1).
- **Verificación:** pruebas de esquema (`CHECK`, claves compuestas, cascada, valores por defecto), subida y bajada de las migraciones en una base local y comprobación de que las actividades y salas existentes siguen siendo válidas.

### 4.2 Acciones y reglas

- `CreateRoom` y `UpdateRoom` aceptan `kind`, `capacity`, `capacity_is_public`, `address` y `map_url` (con `StoreRoomRequest` y `UpdateRoomRequest`). `UpdateRoom` rechaza cambiar `kind` si el lugar tiene actividades (DA-10).
- `CreateActivity`, `UpdateActivity` y `SyncActivitySpeakers` **no cambian de firma**. Aceptan `quota`, `meeting_point`, `transport_info` y `contacts` (lista de hasta 5 con `name`, `phone`, `email`, `is_public`). `UpdateActivity` deja exactamente esa lista de responsables si la clave viene, y borra los datos externos si el lugar deja de ser externo.
- `DetectScheduleConflicts` ignora los lugares `external` en el choque de sala y mantiene el aviso de ponente.
- Aviso no bloqueante en `EventActivity::$scheduleWarnings`: cupo mayor que la capacidad del lugar.

### 4.3 Contrato público (lista cerrada, plan 029 §4.3)

- **Resumen de actividad** (`PublicActivityData::summary`): se añade `limitedSeats` (booleano). Nada más.
- **Detalle** (`PublicActivityData::detail`): se añaden `seats` (cupo o nulo), `place` (o nulo) con `name`, `isExternal`, `address`, `mapUrl` y `capacity` (esta última solo si `capacity_is_public`), `meetingPoint`, `transport` y `contacts` (solo los `is_public`, con `name`, `phone` y `email`). Cada valor vacío llega como `null` o lista vacía y la página no lo pinta.
- Se actualizan `docs/api/endpoints.md` (borrador del plan 033), `resources/js/types/program.ts`, las pruebas `CA-029-7` y `CA-031-28`, y la lista de claves del plan 029.
- No hay rutas JSON ni webhooks nuevos.

### 4.4 Interfaz

- **Panel:** la página «Salas» pasa a «Lugares», con tipo, capacidad, dirección y mapa; el botón de la lista de actividades y el menú cambian de texto. El formulario de la actividad añade: **Lugar** (Sin lugar, Sala, Lugar externo) con su selector; al elegir «Lugar externo», punto de reunión, interruptor «Incluye transporte» con su detalle y la lista de responsables; interruptor «Con cupo limitado» con su número. La lista de actividades gana las columnas «Lugar» y «Cupo». Textos en `lang/{es,en}/program.php`.
- **Sitio público (React):** `ActivityCard` muestra la insignia «Cupo limitado»; la página de detalle muestra los bloques de cupo, lugar, reunión, transporte y responsables solo cuando hay datos. Textos por i18n, `Intl`, accesibilidad AA y entrada en el catálogo de componentes (`design.md` §1.4).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Igual que la F2 y la F3 del plan 031: ver, crear, editar y ordenar lugares y editar actividades con `activities.manage` (`org_admin` y `organizer`); eliminar un lugar, `org_admin` (`activities.delete`). Los responsables se editan con la actividad. El público solo lee. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `RoomPolicy` y la `EventActivityPolicy` existentes; no se crean permisos ni Policies. Se prueba el rechazo de `reviewer`, autor y otra organización al fijar lugar, cupo y responsables. |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `ActivityContact`, con `BelongsToOrganization`, `organization_id` `NOT NULL` con clave foránea y prueba de aislamiento en `tests/Feature/Tenancy/`. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No; igual que el resto del programa. Todo se acota al evento y a la organización por claves compuestas. |
| ¿Se guardan datos personales nuevos? | Sí: nombre, teléfono y correo de los responsables (`activity_contacts`). Base: relación de trabajo con la organización; si se publican, consentimiento de la persona, que el formulario recuerda al marcar «visible al público». Retención: mientras exista la actividad. La persona se localiza por su correo, como ya hace `PersonRecords` con contactos, participantes y ponentes (confirmado en F0, 2026-11-26): al anonimizarla se **eliminan** las filas de `activity_contacts` con su correo dentro de la organización (`PersonDataEraser`, `ErasePersonRecords`), `ExportPersonData` las incluye y `ReapplyAnonymizations` las vuelve a eliminar tras restaurar un respaldo. Se actualizan `PersonalDataInventory` y `docs/privacidad.md` §2 y §6 en el mismo cambio. |
| ¿Se suben archivos? | No. |
| ¿Hay dinero, pagos o webhooks? | No. El cupo no es cobro ni boleto. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | Altas y cambios de lugares y de actividades con ids y nombres de campos cambiados; de los responsables solo cuántos hay y qué ids cambian, nunca nombre, teléfono ni correo. |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit con su registro en §9. Prueba primero en todas las fases con lógica, permisos o aislamiento.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Aprobar el plan y cerrar DA-1 a DA-13. Glosario: `Room` como «lugar del evento, sala o externo» y términos «Cupo» y «Responsable de actividad»; `Taller` pasa a «actividad con cupo, así la llama la ANPR». Propuesta de datos en `docs/database-design.md` §8.5. Confirmar con `PersonDataEraser` cómo se localizan y borran los responsables. Nota en E-27 de `docs/plan-de-desarrollo.md`. Sin cambios de código. | S |
| F1 | **Datos.** Migraciones de `rooms`, `event_activities` y `activity_contacts`; `RoomKind` (enum); `ActivityContact`, su fábrica y relaciones; entrada en `PersonalDataInventory`. Pruebas de esquema, de aislamiento y de la guardiana de privacidad escritas antes. CA-035-1, 2, 22 y 23. | M |
| F2 | **Lugares en el panel.** «Salas» pasa a «Lugares»: tipo, capacidad, interruptor de capacidad pública, dirección y mapa con texto de ayuda; bloqueo de cambio de tipo en uso; columnas nuevas. Textos es/en. CA-035-3 a 9. | M |
| F3 | **Actividad.** Cupo, lugar con campos que aparecen según el tipo, punto de reunión, transporte y responsables; reglas en `CreateActivity` y `UpdateActivity` sin cambiar firma; `DetectScheduleConflicts` ignora los externos; aviso de cupo mayor que la capacidad; columnas «Lugar» y «Cupo». **Privacidad de los responsables** en la misma fase en que se vuelven escribibles: localizarlos por correo en `PersonRecords`, eliminarlos en `PersonDataEraser` y `ErasePersonRecords`, incluirlos en `ExportPersonData` y `ReapplyAnonymizations`, y `docs/privacidad.md` §2 y §6. CA-035-10 a 21, 32, 33 y 34. | L |
| F4 | **Sitio público y contratos.** Constructores públicos con la lista cerrada nueva, tipos de TypeScript, `ActivityCard` y la página de detalle, catálogo de componentes, `docs/api/endpoints.md` y actualización de `CA-029-7` y `CA-031-28`. `npm run typecheck`, `lint`, `test` y `build`. CA-035-24 a 31. | M |
| F5 | **Verificación y cierre.** Navegador a 375 y 1280 px con la consola abierta (panel y sitio público), `composer check` completo y revisión con `/revisar-aislamiento`. Deuda a `docs/sprints/deuda-tecnica.md`. | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-035-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Datos y esquema (F1)**

- **CA-035-1:** *Dado* las salas y actividades que existen antes del cambio, *cuando* se aplican las migraciones, *entonces* todas las salas quedan con tipo `room`, sin capacidad, dirección ni mapa, todas las actividades quedan sin cupo ni datos externos, y siguen siendo válidas; al revertir, las columnas y la tabla `activity_contacts` desaparecen sin tocar el resto.
- **CA-035-2:** *Dado* la base, *cuando* se intenta guardar un tipo distinto de `room` o `external`, una capacidad o un cupo menores que 1, o un responsable de una actividad de otro evento u organización, *entonces* la base lo rechaza (`CHECK` y claves compuestas); borrar la actividad borra a sus responsables.

**Lugares (F2)**

- **CA-035-3:** *Dado* un usuario con `activities.manage`, *cuando* crea un lugar con nombre y tipo, *entonces* queda en su evento; el tipo por defecto es `sala`; capacidad, dirección y mapa son opcionales; el nombre sigue siendo obligatorio y único en el evento sin distinguir mayúsculas.
- **CA-035-4:** *Dado* un lugar, *cuando* se guarda con capacidad `0`, negativa, no entera o mayor que 100 000, *entonces* se rechaza con error de validación; con un valor válido o vacío, se guarda.
- **CA-035-5:** *Dado* un lugar, *cuando* se guarda con un mapa que no es una URL `https` de hasta 2048 caracteres, o una dirección de más de 255, *entonces* se rechaza; los espacios sobrantes se quitan y un texto vacío se guarda como nulo.
- **CA-035-6:** *Dado* un lugar con actividades asignadas, *cuando* se intenta cambiar su tipo, *entonces* falla con un error que dice cuántas actividades lo usan y no cambia nada; un lugar sin actividades sí cambia de tipo.
- **CA-035-7:** *Dado* un usuario `reviewer`, un autor o uno de otra organización, *cuando* intenta crear, editar u ordenar lugares, *entonces* se deniega en el servidor; un lugar de otra organización consultado por identificador responde 404 (prueba de aislamiento).
- **CA-035-8:** *Dado* la página «Lugares», *cuando* se abre, *entonces* lista nombre, tipo y capacidad, con el estado vacío, de carga y de error, y los textos de ayuda de dirección y mapa orientan al organizador (edificio, piso, ala o entrada; en un lugar externo, calle y colonia o una referencia).
  Manual: abrir Lugares a 375 y 1280 px, crear una sala y un lugar externo, comprobar los textos de ayuda y la consola.
- **CA-035-9:** *Dado* un lugar con capacidad, *cuando* se marca «mostrar la capacidad al público», *entonces* el interruptor se guarda y por defecto está apagado.

**Actividad (F3)**

- **CA-035-10:** *Dado* una actividad, *cuando* se guarda con cupo entero de 1 a 100 000, *entonces* se guarda; vacío significa «sin cupo»; `0`, negativo o no entero se rechaza; el cupo funciona igual con lugar sala, lugar externo o sin lugar.
- **CA-035-11:** *Dado* una actividad con cupo mayor que la capacidad de su lugar, *cuando* se guarda, *entonces* se guarda y el resultado incluye un aviso con los dos números; sin capacidad o sin cupo no hay aviso.
- **CA-035-12:** *Dado* una actividad cuyo lugar es externo, *cuando* se guarda con punto de reunión, transporte y responsables, *entonces* se guardan; el punto de reunión y el transporte admiten hasta 500 caracteres y se guardan sin espacios sobrantes; un transporte nulo significa «sin transporte».
- **CA-035-13:** *Dado* una actividad cuyo lugar es una sala o sin lugar, *cuando* se envían punto de reunión, transporte o responsables, *entonces* se rechaza con error de validación y no se escribe nada.
- **CA-035-14:** *Dado* una actividad externa con esos datos, *cuando* se cambia a una sala o a «sin lugar», *entonces* el punto de reunión, el transporte y los responsables se borran en la misma transacción y la auditoría lo registra sin valores personales.
- **CA-035-15:** *Dado* los responsables de una actividad, *cuando* se guardan, *entonces* cada uno exige nombre (hasta 120 caracteres), el teléfono (hasta 40, solo dígitos, espacios y `+-().`) y el correo (válido, hasta 254) son opcionales, `is_public` es falso si no se indica, el máximo es 5 y se conserva el orden; un sexto o un correo inválido se rechaza.
- **CA-035-16:** *Dado* dos actividades al mismo tiempo en el mismo lugar **externo**, *cuando* se guardan, *entonces* se aceptan; en una **sala** siguen rechazándose con `ACTIVITY_ROOM_CONFLICT` (el comportamiento de `CA-031-10` a 14 no cambia).
- **CA-035-17:** *Dado* un ponente en dos actividades que se cruzan, aunque una o las dos sean en lugares externos, *cuando* se guarda, *entonces* se guarda con el aviso de siempre.
- **CA-035-18:** *Dado* las Actions `CreateActivity`, `UpdateActivity` y `SyncActivitySpeakers`, *cuando* se llaman con los argumentos de antes, *entonces* siguen funcionando con la misma firma y sus pruebas anteriores pasan sin cambios.
- **CA-035-19:** *Dado* un cambio que falla en cualquier paso (lugar, cupo, responsables, ponentes), *cuando* se ejecuta, *entonces* no queda ninguna escritura parcial.
- **CA-035-20:** *Dado* un usuario `reviewer`, un autor o uno de otra organización, *cuando* intenta fijar lugar, cupo, transporte o responsables, *entonces* se deniega en el servidor; un lugar de otro evento u organización se rechaza como inexistente.
- **CA-035-21:** *Dado* el formulario de la actividad, *cuando* se elige «Lugar externo», *entonces* aparecen el punto de reunión, el transporte con su interruptor y los responsables; con «Sala» o «Sin lugar» se ocultan; «Con cupo limitado» muestra el campo del número y apagarlo lo vacía; la lista muestra las columnas «Lugar» y «Cupo».
  Manual: crear una actividad externa con cupo y dos responsables, y una en sala sin cupo, a 375 y 1280 px con la consola abierta.

**Sitio público (F4)**

- **CA-035-24:** *Dado* un programa con actividades con y sin cupo, *cuando* se abre, *entonces* solo las que tienen cupo muestran la insignia «Cupo limitado»; las demás no muestran nada; ningún número de cupo ni de capacidad aparece en el programa, y las props del resumen son exactamente las de antes más `limitedSeats`.
- **CA-035-25:** *Dado* el detalle de una actividad con cupo, *cuando* se abre, *entonces* muestra el número de cupo sin botón ni texto de inscripción; sin cupo no muestra nada de cupo.
- **CA-035-26:** *Dado* una actividad con lugar, *cuando* se abre su detalle, *entonces* muestra el nombre del lugar y, solo si existen, la dirección y el mapa; sin lugar no muestra el bloque; la capacidad solo aparece si el lugar la marca pública.
- **CA-035-27:** *Dado* una actividad externa con punto de reunión y transporte, *cuando* se abre su detalle, *entonces* los muestra; si no hay datos, no hay bloque ni texto vacío.
- **CA-035-28:** *Dado* una actividad con responsables públicos y no públicos, *cuando* se construyen las props y se abre el detalle, *entonces* solo salen los públicos, con los datos que tengan; los demás no aparecen en ninguna prop, página ni respuesta.
- **CA-035-29:** *Dado* los datos de una actividad con todos sus campos llenos, *cuando* se construyen las props públicas, *entonces* las claves son **exactamente** las de la lista cerrada nueva (§4.3) y ningún valor interno sale (identificadores, organización, auditoría, responsables no públicos, capacidad no pública).
- **CA-035-30:** *Dado* un texto con marcas o un mapa con esquema no permitido, *cuando* se muestra, *entonces* se escapa y el enlace solo se emite si es `https` con `rel="noopener noreferrer"`.
- **CA-035-31:** *Dado* el sitio público, *cuando* se ve a 375 y 1280 px, *entonces* la insignia y los bloques del detalle se leen sin desbordar, con contraste AA, textos por i18n (es y en) y sin errores en consola.
  Manual: abrir el programa y el detalle de una actividad externa con cupo a 375 y 1280 px; esperar o hacer scroll antes de concluir un fallo por animaciones de entrada.

**Privacidad (F1 y F3)**

- **CA-035-22:** *Dado* el modelo `ActivityContact`, *cuando* corre `PersonalDataInventoryTest`, *entonces* figura como cubierto con todas sus columnas clasificadas, y `docs/privacidad.md` §2 lo describe.
- **CA-035-23:** *Dado* el nuevo modelo, *cuando* corren las pruebas de aislamiento de `tests/Feature/Tenancy/`, *entonces* una organización no ve ni modifica los responsables de otra, y sin contexto de organización la consulta falla.
- **CA-035-32:** *Dado* una persona cuyo correo figura como responsable en actividades de la organización, *cuando* se anonimiza a la persona, *entonces* esas filas se eliminan en esa organización (y no en otra), el resultado de la operación cuenta cuántas se eliminaron, y el registro de la operación no guarda su nombre ni su correo; si se restaura un respaldo, `ReapplyAnonymizations` las vuelve a eliminar.
- **CA-035-34:** *Dado* una persona cuyo correo figura como responsable, *cuando* se exportan sus datos, *entonces* el archivo incluye esas filas (nombre, teléfono, correo, actividad y si es público) y solo las de esa organización.
- **CA-035-33:** *Dado* el formulario, *cuando* se marca a un responsable como visible al público, *entonces* el texto de ayuda recuerda que la persona debe haber dado su permiso.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| **Publicar un dato personal de un responsable** sin querer | `is_public` apagado por defecto, constructores públicos con lista cerrada, CA-035-28 y 29, y el aviso de permiso (CA-035-33). |
| **Cambiar el contrato público** del plan 029 y romper a quien lo consume (sitio, app móvil) | Lista de claves exacta con prueba, tipos de TypeScript actualizados en el mismo cambio y `docs/api/endpoints.md` al día; el cambio es solo aditivo. |
| **Dejar a los responsables fuera del borrado de una persona** | `PersonalDataInventory` y su guardiana (CA-035-22), borrado y exportación por correo en `PersonRecords` (CA-035-32 y 34), ya confirmado en F0. |
| **Romper la regla de choque** de la F3 al abrir una excepción para los externos | `DetectScheduleConflicts` sigue siendo la única definición; las pruebas `CA-031-10` a 17 se conservan y se añaden CA-035-16 y 17. |
| **Lugares externos y actividades con datos huérfanos** al cambiar de tipo | Cambio de tipo bloqueado si el lugar está en uso (DA-10) y borrado de los datos externos al mover la actividad (CA-035-14). |
| **Confundir cupo con inscripción:** el sitio parece prometer plazas | Texto «Cupo: n personas», sin botón ni «lugares disponibles», y E-27 documentado como la fase que añade la inscripción. |
| **Agenda de la F4 (plan 031) mal planteada** si se construye antes | Orden de trabajo del DA-12: este plan se fusiona antes de la F4 y la columna «Fuera de sede» queda fijada en DA-9. |
| **Migraciones aplicadas a una base real sin querer** | Las genera el agente y solo las aplica una persona; se prueban subida y bajada en una base local (CA-035-1). |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | ver `git log` | 2026-11-26 | Plan en `Aprobado` (DA-1 a DA-13 cerradas). Glosario: `Sala` como «lugar del evento» con tipo, `Taller` como actividad con cupo, y los términos «Cupo» y «Responsable de actividad». Propuesta de datos en `docs/database-design.md` §8.5. `docs/privacidad.md` §2 y §6 con los responsables. Confirmado con `PersonRecords`, `PersonDataEraser`, `ExportPersonData` y `ReapplyAnonymizations` que los responsables se localizan por correo y se eliminan al anonimizar (CA-035-32 y 34, en F3). Nota en E-27 de `docs/plan-de-desarrollo.md`. Sin cambios de código ni migraciones. |
| F1 | `e6c8391` | 2026-11-26 | Rama `feat/plan-035-lugares-y-cupos`. Datos, sin cambios de comportamiento. Tres migraciones: `2026_11_30_100000` (`rooms`: `kind`, `capacity`, `capacity_is_public`, `address`, `map_url`, con `CHECK` de tipo y de capacidad), `2026_11_30_100100` (`event_activities`: `quota`, `meeting_point`, `transport_info`, `CHECK` de cupo) y `2026_11_30_100200` (`activity_contacts`: claves compuestas por evento y organización, cascada con la actividad, único `(activity_id, position)`, índice por `lower(email)`, trigger de organización inmutable y **RLS desde la migración**, que la guardiana exige). `RoomKind`, `ActivityContact`, su fábrica, `Room` y `EventActivity` con sus casts y la relación `contacts()`. `PersonalDataInventory`: `ActivityContact` declarado como borrado; el borrado por correo llega en la F3 y hasta entonces nada escribe en la tabla. Cubre CA-035-1, 2 y 23: 9 pruebas en `PlaceSchemaTest`, escritas antes y vistas fallar (9 en rojo). Migraciones probadas en la base local: subir (las 5 salas quedan como `room` sin capacidad y las 10 actividades sin cupo), bajar 3 (desaparecen columnas y tabla) y volver a subir. Guardianas de inventario, RLS, aislamiento y Arch (406 pasan) y `tests/Feature/Program` (426 pasan); `composer check` completo sobre `staging` antes de la F1: 3236 pasan. PHPStan «No errors» y Pint limpio. Las migraciones las aplica una persona a las bases reales; hasta ahora solo en la base local. |
| F2 | `8b67724` | 2026-11-26 | Lugares en el panel. `RoomWrites` (limpieza de entrada y columnas a guardar), reglas en `StoreRoomRequest` (`kind`, `capacity` de 1 a 100 000, `capacity_is_public`, `address` hasta 255, `map_url` solo `https` hasta 2048), `CreateRoom` y `UpdateRoom` ampliadas sin cambiar firma: lo que no viene no cambia y el nulo vacía capacidad, dirección y mapa; cambiar el tipo de un lugar con actividades se rechaza con el conteo, con el evento bloqueado antes (como `DeleteRoom`); auditoría con los campos cambiados. La página pasa de «Salas» a «Lugares»: formulario con tipo, capacidad, interruptor de capacidad pública (solo aparece si hay capacidad), dirección y mapa con texto de ayuda (edificio, piso, ala, entrada), y columnas Tipo y Capacidad. Textos es y en. Cubre CA-035-3 a 7 y 9 con 22 pruebas en `PlaceActionsTest` y 3 en `RoomPanelTest`, escritas antes del código y vistas fallar (28 en rojo). Lote Program, Arch, Public, idiomas e inventario de privacidad: 718 pasan. PHPStan «No errors» y Pint limpio. Navegador a 1280 y 375 px, consola sin errores: lista con tipo y capacidad, formulario con las ayudas, el interruptor de capacidad pública aparece al llenar la capacidad, alta de un lugar externo con capacidad, dirección y mapa, y el bloqueo al cambiar de tipo el Auditorio principal con el mensaje «3 actividad(es) usan este lugar». CA-035-8 verificado a mano. Sin verificar: el interruptor de capacidad pública al editar un lugar existente. Pendiente menor: las migas de pan siguen en inglés («Rooms»). |

## 10. Deuda que deja (a registrar en F5)

- Inscripción, lista de espera y conteo de cupos ocupados (E-22 y E-27).
- Capacidad por montaje y equipamiento de los lugares, solo si algún cliente lo pide. Prioridad P3, esfuerzo M.
- Que una categoría de actividad preconfigure cupo y tipo de lugar. Prioridad P3, esfuerzo S.
- Reutilizar lugares entre eventos o sedes (plan 031 §10). Prioridad P3, esfuerzo M.
- Teléfono de los responsables en una comprobación más estricta que el patrón de caracteres (por país). Prioridad P3, esfuerzo S.
