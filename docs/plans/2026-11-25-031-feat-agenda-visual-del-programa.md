# feat: Agenda visual del programa (E-20): salas, vista por día y sala, y reglas de choques

> Plan 031. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 031: todos sus criterios son `CA-031-n`. Cubre **E-20** en su primera entrega: la agenda dentro del panel como segunda vista de las actividades. El sitio público no cambia (plan 029).
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-25
- **Estado:** Aprobado (2026-11-25: Antonio cierra DA-1 a DA-13 con la recomendación del plan; S-11 se cierra con DA-4)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna abierta. Este plan propone puntos nuevos (§10) y cierra la decisión S-11 de `docs/plan-de-desarrollo.md`.

## 1. Objetivo y alcance

Hoy el programa de un evento solo se lee como una **lista de actividades** (plan 021): sirve para buscar, filtrar y editar, pero no deja ver qué ocurre al mismo tiempo ni dónde se empalman dos cosas. El plan 021 dejó fuera la agenda visual (E-20) y no existía plan para ella. Este plan hace dos cosas, en este orden:

1. **Mejora previa de la lista:** agruparla por día y ordenarla por hora, para que ya se lea como un programa.
2. **Agenda visual:** una segunda vista, como pestaña dentro de la misma pantalla de Actividades, con **un día por vista, una columna por sala y las horas en vertical**. Las dos vistas leen las mismas actividades; no hay datos duplicados. Incluye **salas**, **reglas de choques** y mover actividades **arrastrándolas** (con alternativa de teclado).

**Dentro:**
- Lista de Actividades agrupada por día y ordenada por hora, con «Sin programar» al final.
- Salas del evento (`Room`): catálogo propio de cada evento, con alta, edición, orden y eliminación; una actividad programada puede tener una sala (opcional).
- Reglas de choques: **sala ocupada** (bloquea) y **ponente en dos actividades a la vez** (avisa), aplicadas en el servidor en todas las rutas que cambian horario, sala o ponentes.
- Pestaña **Agenda** en la pantalla de Actividades: cuadrícula día × sala, marcas de choque, panel lateral de actividades sin programar, y mover por arrastre o con la acción «Mover…».
- Decisión **S-11** (tecnología del editor de agenda) cerrada en este plan.

**Fuera:**
- Versiones del programa y publicación por versiones (`Schedule`, `ScheduleBlock`), exportación (PDF, Excel, `.ics`), pósters y su sala. Siguen siendo E-20 y se piden en planes posteriores.
- Mostrar la sala en el sitio público: este plan **no cambia** las props públicas del plan 029 (DA-9).
- Sedes (`Venue`) y salas compartidas entre eventos, aforo o capacidad de sala, equipamiento, tiempos de traslado.
- Cambiar la duración arrastrando el borde, selección múltiple, deshacer, copiar un día a otro.
- Mover por arrastre las actividades de varios días o que cruzan la medianoche (se editan con el formulario).
- Traducción del nombre de la sala por idioma (como categorías, ponentes y actividades, es de un solo idioma).
- Cambiar reglas de autorización: se reutilizan los permisos del plan 021 (acción 9 de `AGENTS.md`).

## 2. Punto de partida

Medido el 2026-11-25 sobre `staging` (`d7395d9`):

- **Pantalla de Actividades.** `app/Filament/Resources/Events/Pages/ManageEventActivities.php`, página `ManageRelatedRecords` del `EventResource` (ruta `/{record}/activities`). Es una tabla con columnas de imagen, título, fecha, hora (con la zona del evento), categorías, ponentes, publicada, «oculto por reversa» y propuesta de origen. Orden por defecto `starts_on`, `starts_at`, `title`; filtros por categoría, publicada y programada/sin programar; paginación 25/50/100. **No agrupa por día.**
- **Modelo.** `EventActivity` (`event_activities`): `starts_on`/`ends_on` (`date`) y `starts_at`/`ends_at` (`time`) **locales del evento y sin zona** (DA-12 del plan 021); las cuatro o ninguna; `CHECK` de fin no anterior al inicio (`ends_on >= starts_on`, y el mismo día `ends_at >= starts_at`), por lo que **una actividad puede durar varios días o cruzar la medianoche**. Ponentes por `activity_speaker` con `position`. Índice `(organization_id, event_id, starts_on, starts_at)`. Las propuestas aceptadas crean la actividad **sin fecha ni hora** (plan 021, DA-4).
- **No existe el concepto de sala.** Ninguna migración crea `rooms` ni `venues`. El evento solo guarda `venue_name` y `venue_address` como texto (`create_events_table`). `event_activities` no tiene sala. El glosario ya propone `Room` («espacio dentro de una sede») y `Venue` con ámbito **Organización**, además de `Schedule` y `ScheduleBlock`, sin construir.
- **Evento.** Tiene `starts_at` y `ends_at` (instantes UTC), `timezone` y `time_format`; `EventTime` formatea la hora local (plan 023).
- **Permisos.** `EventActivityPolicy` decide con `activities.manage` (ver, crear, editar, publicar) y `activities.delete` (eliminar); los tienen `org_admin` y `organizer`; `reviewer` no ve nada (plan 021, DA-13).
- **Acciones existentes.** `app/Actions/Program/`: `CreateActivity`, `UpdateActivity`, `SetActivityPublication`, `DeleteActivity`, `SyncActivitySpeakers`, `PromoteSubmissionToProgram` y `HideProgramOfSubmission`. Reciben un array validado; la firma no cambia si se agrega una clave al arreglo.
- **Tecnología del panel.** Filament `v5.11.4` sobre Livewire `v4.4.8`. `package.json` **no** incluye librerías de calendario ni de arrastre. Filament incluye `sortable.js` (SortableJS) en `vendor/filament/support/resources/js/`. React vive en `resources/js/` y es del sitio público (ADR-0004); el panel no monta React.
- **Plan 029 en curso.** Su F2 está hecha y F3 a F5 pendientes (rama `feat/plan-029-ponentes-y-detalle`). Toca `EventActivity` (scope `visibleToPublic`) y la capa pública, no la pantalla del panel. Este plan no cambia nada público.
- **Numeración.** El último plan fusionado es el 029 (`2026-11-24-029-...`); el 030 (evento no disponible) esta en curso en otra rama sin fusionar, por eso este plan es el 031 (se habia nombrado 030 por error y se renumero el 2026-11-25).
- **Deuda.** `docs/sprints/deuda-tecnica.md` no tiene puntos abiertos sobre programa, salas ni agenda.
- **Plan de desarrollo.** E-20 «Programa y agenda» está en R3, esfuerzo L, depende de E-12 y E-19, con entregable «Agenda sin choques»; S-11 (Livewire o React dentro de Filament) está `[PENDIENTE]` y esperaba este plan.

## 3. Decisiones

**Cerradas el 2026-11-25** por Antonio: DA-1 a DA-13 se aprueban tal como se recomiendan. Lo comprobado en F0 que afina el texto: la página de Actividades puede alternar vistas sobrescribiendo `content()` (Filament 5 lo permite y ya trae el mecanismo de pestañas `HasTabs`, DA-1); las categorías del plan 021 se borran físicamente (`DeleteProgramCategory`), así que la sala tampoco lleva borrado lógico (DA-13).

### DA-1 · Dónde vive la agenda — se recomienda **pestañas dentro de la misma página de Actividades**

Opciones: (a) una pestaña «Agenda» junto a «Lista» en la pantalla de Actividades, con `?vista=lista|agenda` en la dirección; (b) un módulo aparte en el menú. Las dos vistas muestran las mismas filas, así que (a) deja una sola entrada, una sola fuente de datos y un solo permiso, y el menú del evento (plan 020) no crece. La vista elegida se recuerda en la dirección y se puede compartir. F0 comprueba cómo alternar vistas en una página `ManageRelatedRecords` de Filament 5 sin romper la tabla.

### DA-2 · Dueño de la sala — se recomienda **sala propia de cada evento, sin sedes**

Opciones: (a) `Room` con ámbito **Evento**, catálogo propio de cada evento (como las categorías del plan 021, DA-7); (b) `Venue` y `Room` de la **Organización**, reutilizables entre ediciones (como propone hoy el glosario). Con (b) hay que construir sedes, su alta y su relación con el evento, y ninguna se pide todavía; el evento solo tiene `venue_name` de texto. Recomendación (a): menos piezas y se comporta como el resto del programa; cuando se construya la sede se puede ofrecer «copiar salas de otro evento» (el plan 019 ya duplica eventos). F0 corrige el glosario: `Room` pasa a ámbito **Evento**.

### DA-3 · Cómo se relaciona la actividad con la sala — se recomienda **sala opcional, una por actividad, solo si está programada**

`event_activities.room_id` (nulo). Una actividad sin sala puede tener horario (plenaria, por asignar) y aparece en la columna «Sin sala» de la agenda; una actividad **sin programar no puede tener sala** (`CHECK`: `room_id` nulo si `starts_on` es nulo), porque una sala sin horario no ocupa nada. Una actividad usa una sola sala (varias salas para una misma sesión quedan fuera).

### DA-4 · Tecnología del editor (S-11) — se recomienda **Livewire con Alpine y arrastre nativo, sin dependencias nuevas**

Opciones: (a) componente Livewire propio dentro de Filament, con Alpine y los eventos nativos de arrastre (se evalúa reutilizar el `sortable.js` que ya trae Filament); (b) React dentro de Filament; (c) una librería de calendario (FullCalendar u otra). Motivos: el panel es Livewire, la sesión, los permisos, la auditoría y las Actions ya viven ahí y se reutilizan tal cual; React no se monta en el panel y exigiría una entrada de compilación y un puente de datos nuevos; una librería es una **dependencia nueva** (acción 4 de `AGENTS.md`, exige permiso) y su licencia o peso no están evaluados. Costo de (a): la cuadrícula y el arrastre se escriben a mano. Se acota con una cuadrícula fija (DA-7) y con la regla de que **el servidor decide todo**: el navegador solo propone «esta actividad a este día, hora y sala». F0 hace una prueba de concepto desechable (arrastrar una tarjeta entre celdas en una página Filament) antes de comprometer F4 y F5; si falla, se vuelve a esta decisión y se avisa. Cerrar S-11 con esta opción en `docs/plan-de-desarrollo.md` §S-11.

### DA-5 · Qué es un choque y qué hace el sistema — se recomienda **sala ocupada bloquea; ponente repetido avisa**

- **Cuándo se solapan dos actividades:** sus intervalos **medio abiertos** `[inicio, fin)` se cruzan, calculados con la fecha y la hora locales del evento de cada extremo (`starts_on + starts_at` hasta `ends_on + ends_at`). Terminar a las 10:00 y empezar a las 10:00 **no** es choque. Así una actividad de varios días o que cruza la medianoche choca con lo que se le cruce.
- **Sala ocupada** (dos actividades en la misma sala, solapadas): el sistema **rechaza** el cambio con un error explícito que nombra la actividad en conflicto. Una sala física no admite dos sesiones a la vez.
- **Ponente repetido** (el mismo ponente en dos actividades solapadas, en cualquier sala): el cambio **se guarda** y el sistema **avisa** con los nombres; la agenda y la lista marcan ambas actividades. Hay casos válidos (un moderador que abre un panel y presenta en otro), así que no se bloquea.
- **Sin sala:** una actividad sin sala no genera choque de sala; el choque de ponente sí aplica.
- Alternativa descartada: bloquear también el choque de ponente (impide casos reales) o avisar también el de sala (deja una agenda imposible de ejecutar).

### DA-6 · Qué actividades cuentan para los choques — se recomienda **todas las no eliminadas, publicadas o no**

Cuentan las actividades no eliminadas, **incluidas** las no publicadas y las ocultas por reversa; solo el borrado lógico las excluye. Razón: una actividad en borrador sigue siendo un compromiso de planeación, y si las ocultas por reversa no contaran, **republicar** una (`SetActivityPublication`) podría crear un choque sin pasar por ninguna validación. Así publicar y despublicar nunca cambian si hay choque, y no hace falta revalidar al publicar. Costo: una actividad retirada por reversa sigue ocupando su sala hasta que el organizador la mueva o la elimine; la agenda la muestra atenuada con la insignia «Oculto por reversa».

### DA-7 · Cuadrícula y arrastre — se recomienda **pasos de 15 minutos, un día a la vez, rango automático**

Un día por vista (selector de días del evento: los días locales entre el inicio y el fin del evento más cualquier día con actividades). Las filas son de 15 minutos; al soltar, la hora se ajusta a la fila. El rango visible va de una hora antes de la primera actividad del día a una hora después de la última, con un mínimo de 08:00 a 18:00 si el día está vacío. Una columna por sala (en el orden de `position`) más «Sin sala». Actividades de varios días o que cruzan la medianoche: se **muestran** bajo su día de inicio con un indicador y se mueven con el formulario, no por arrastre. La duración al arrastrar se **conserva**; el fin lo calcula el servidor.

### DA-8 · Actividades sin programar en la agenda — se recomienda **panel lateral de pendientes**

Las actividades sin fecha (por ejemplo, las nacidas de propuestas aceptadas) se listan en un panel junto a la cuadrícula. Arrastrar una a una celda la programa (día, hora de inicio, sala) con una **duración por defecto de 60 minutos**, ajustable después en el formulario. Es lo que permite «ir formando el programa» sin salir de la agenda.

### DA-9 · Sitio público — se recomienda **sin cambios**

La sala no se publica en este plan: las props públicas del plan 029 (lista cerrada de campos, DA-6 de ese plan) quedan **exactamente** igual, y una prueba de regresión lo vigila. Mostrar la sala al visitante es otro plan (§10) porque cambia el contrato público y lo que el organizador espera publicar.

### DA-10 · Garantía de no choque — se recomienda **comprobación en la Action con el evento bloqueado**

Opciones: (a) comprobar en la Action, dentro de una transacción que bloquea la fila del evento (`lockForUpdate`) para serializar los cambios de horario de ese evento; (b) una restricción de exclusión de PostgreSQL (`btree_gist` sobre un rango calculado de fecha y hora y la sala). (b) es la garantía más fuerte, pero necesita una extensión en cada entorno (acciones 1 y 12 de `AGENTS.md`) y expresar rangos que cruzan la medianoche con columnas `date` y `time` separadas. Recomendación (a) ahora, con una prueba de que la comprobación y la escritura ocurren en la misma transacción bloqueada; (b) queda como deuda P3 (§10). Los cambios son manuales y de pocas personas por evento, así que bloquear por evento no es un cuello de botella.

### DA-11 · Dónde se aplica la regla — se recomienda **en las Actions, una sola implementación**

Una pieza de consulta (`DetectScheduleConflicts`) calcula los choques de un evento o de un cambio propuesto; `CreateActivity`, `UpdateActivity`, la nueva `MoveActivity` y `SyncActivitySpeakers` la usan. Ninguna pantalla duplica la regla. Cambia el **comportamiento** de las Actions existentes (nueva clave `room_id`, nuevo rechazo), no su firma pública (acción 3 de `AGENTS.md`); sus pruebas actuales siguen pasando y se amplían.

### DA-12 · Lista agrupada por día — se recomienda **agrupada por defecto, con grupo «Sin programar» al final**

Se usa la agrupación de la tabla de Filament sobre `starts_on`, con encabezado de fecha larga en el idioma del usuario. Dentro de cada día: hora de inicio, hora de fin, título. El grupo «Sin programar» va al final. El usuario puede quitar la agrupación. Una actividad de varios días aparece bajo su día de inicio (igual que en el sitio público, plan 029 CA-029-13). No cambia datos.

### DA-13 · Eliminar una sala usada — se recomienda **bloquear con error explícito**

No se puede eliminar una sala con actividades asignadas: el error dice cuántas son; hay que moverlas o quitarles la sala antes. No se las deja sin sala en silencio. F0 revisa cómo tratan las categorías del plan 021 el borrado y alinea el comportamiento (sin `SoftDeletes` si las categorías tampoco lo usan, porque la sala es un catálogo sin historial propio).

## 4. Contrato

No hay rutas JSON ni webhooks: todo corre por Livewire dentro del panel, de modo que `docs/api/endpoints.md` **no cambia**.

### 4.1 Datos (propuesta para `docs/database-design.md` §6, a escribir en F0 y aprobar con el plan)

Las migraciones **no se escriben ni se aplican a una base real** sin la propuesta aprobada (acción 1).

- **`rooms`** (nueva): `id uuid`, `organization_id`, `event_id`, `name varchar(100)`, `position int`, `timestamps`. Único `(event_id, lower(name))`. `UNIQUE (id, event_id, organization_id)` para la clave foránea compuesta. Clave foránea compuesta `(event_id, organization_id)` hacia `events`, igual que las demás tablas del evento. Sin capacidad ni sede (YAGNI, §1).
- **`event_activities.room_id`** (columna nueva, nula): clave foránea compuesta `(room_id, event_id, organization_id)` hacia `rooms`, para que la sala sea del mismo evento y organización. `CHECK (room_id IS NULL OR starts_on IS NOT NULL)` (DA-3). Índice `(event_id, room_id, starts_on, starts_at)` para la consulta de choques (F0 lo confirma con `EXPLAIN`; si no hace falta, no se crea).
- Sin cambios a `event_activities` más allá de esa columna; sin cambios a `speakers` ni a `activity_speaker`.

### 4.2 Acciones y clases (`app/Actions/Program/` y `app/Support/Program/`)

- `CreateRoom`, `UpdateRoom`, `DeleteRoom` (con el bloqueo de DA-13) y el reordenamiento por `position`, cada una con su Form Request.
- `MoveActivity`: recibe la actividad, el día, la hora de inicio y la sala (nula = «Sin sala»). Calcula el fin con la duración actual (o 60 minutos si estaba sin programar, DA-8), valida y escribe en una transacción con el evento bloqueado.
- `DetectScheduleConflicts` (en `app/Support/Program/`, solo lectura): devuelve los choques de sala y de ponente de un evento, un día o un cambio propuesto. Es la única definición de «solapan» (DA-5).
- `CreateActivity`, `UpdateActivity` y `SyncActivitySpeakers`: aceptan `room_id`, aplican las reglas de DA-5 y devuelven los avisos de ponente junto con el resultado.
- Excepción de dominio `ActivityRoomConflict` (`App\Exceptions\Domain`, código `ACTIVITY_ROOM_CONFLICT`) con el título de la actividad en conflicto. Un error de base se propaga; nunca se degrada a «sin choques».
- `RoomPolicy` (una por modelo): usa `activities.manage` para ver, crear, editar y ordenar, y `activities.delete` para eliminar; no se crean permisos nuevos.

### 4.3 Pantalla (Filament, solo panel)

- `ManageEventActivities` gana la agrupación por día (DA-12) y la alternancia de vistas (DA-1). La gestión de salas se hace desde un modal o una sección dentro de la misma pantalla (como los atajos de categorías, `ProgramCategoryShortcuts`) o con una página hermana si F0 comprueba que es más simple.
- Componente Livewire de la agenda (cuadrícula día × sala, panel de pendientes, marcas de choque) y acción «Mover…» (modal con día, hora y sala) que llama a la misma `MoveActivity`. Textos nuevos en `lang/{es,en}/program.php`.
- El formulario de la actividad (alta y edición) gana el selector de sala, solo con las salas del evento.

### 4.4 Lo que no cambia

`PublicActivityData`, `PublicSpeakerData` y las rutas del plan 029; `PromoteSubmissionToProgram` y `HideProgramOfSubmission` (crean y ocultan actividades sin fecha, así que no generan choques).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` y `organizer`: ver la lista y la agenda, crear y editar salas, asignar sala y mover actividades. `org_admin`: eliminar salas. `reviewer` y autores: nada (como el plan 021, DA-13). Cambiar de pestaña no cambia el permiso: la agenda exige `activities.manage` |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `RoomPolicy` nueva y `EventActivityPolicy` existente (la acción «mover» es `update`). Pruebas en `tests/Feature/Program/RoomPolicyTest.php` y `ActivityMoveTest.php`. Las Actions comprueban el permiso por su cuenta, sin depender de que el botón esté visible |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `Room`. Usa `BelongsToOrganization`; se agrega a las pruebas guardianas de `tests/Feature/Tenancy/` y a la de aislamiento |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No depende de asignaciones. **Nunca cruza eventos**: la sala de una actividad es del mismo evento por clave foránea compuesta y por validación; el evento sale de la ruta, así que uno de otra organización responde 404 |
| ¿Se guardan datos personales nuevos? | **No.** La sala es un nombre de espacio. Los avisos de choque muestran nombres de ponentes que quien tiene `activities.manage` ya ve en la pantalla de Ponentes y Actividades; no se publican ni se envían |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No. Los avisos son notificaciones en pantalla del panel |
| ¿Qué se registra en la auditoría? | Alta, edición, reorden y eliminación de salas; cada movimiento de actividad con antes y después de fecha, hora y sala (identificadores y valores, sin datos personales); el aviso de choque de ponente no se audita (no cambia datos por sí solo) |

Seguridad: el servidor valida de nuevo todo lo que propone el navegador (la sala y la actividad pertenecen al evento, el día y la hora son válidos, el fin se recalcula); un identificador de otro evento u organización responde como inexistente. Sin consultas que salten el scope global.

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit. Prueba primero en las reglas de choque, el aislamiento y los permisos (ADR-0001).

| Fase | Qué se hace | Criterio de terminado | Esfuerzo |
|---|---|---|---|
| F0 | Aprobar este plan y cerrar DA-1 a DA-13. Glosario (`Room` con ámbito Evento; término «Agenda»), propuesta de datos en `docs/database-design.md` §6, S-11 cerrada en `docs/plan-de-desarrollo.md`. Comprobar cómo alternar vistas en `ManageRelatedRecords` (DA-1), cómo tratan las categorías el borrado (DA-13), el `EXPLAIN` del índice (§4.1) y la prueba de concepto de arrastre (DA-4) | Plan en `Aprobado`; decisiones registradas en §3 y en el commit; la prueba de concepto confirma o cambia DA-4 | S |
| F1 | **Lista agrupada por día y ordenada por hora** (DA-12). No toca datos ni Actions | CA-031-1 a 4 verdes; `composer check` en verde con su salida; verificación en navegador a 375 y 1280 px | S |
| F2 | **Salas:** migraciones de `rooms` y de `event_activities.room_id` (la columna entra aquí porque el bloqueo de borrado la necesita), modelo, factory, `RoomPolicy`, Actions y Form Requests, gestión en el panel, orden, bloqueo de borrado, pruebas de aislamiento | CA-031-5 a 8 verdes; `ModelsAreScoped` y `TenantIsolation` incluyen `Room` | M |
| F3 | **Sala en la actividad y reglas de choque:** selector en el formulario, columna «Sala» en la lista, `DetectScheduleConflicts`, rechazo de sala ocupada y aviso de ponente en `CreateActivity`, `UpdateActivity` y `SyncActivitySpeakers` | CA-031-9 a 18 y 28 verdes (pruebas escritas **antes** del código y vistas fallar) | M |
| F4 | **Agenda de lectura:** pestaña, selector de día, cuadrícula día × sala, marcas de choque, panel de pendientes y estados vacío, de carga y de error. Sin arrastre | CA-031-19, 20, 24, 25 y 29 verdes; navegador a 375 y 1280 px | M |
| F5 | **Mover:** `MoveActivity`, arrastre en la cuadrícula y desde el panel de pendientes, acción «Mover…» con teclado, auditoría | CA-031-21 a 23, 26 y 27 verdes; verificación manual de CA-031-30 | L |
| F6 | Verificación completa en navegador (375 y 1280 px, consola, teclado), `composer check` y `npm run typecheck`, `lint`, `test` y `build` si se tocó `resources/js`, deuda de §10 en `docs/sprints/deuda-tecnica.md` (commit `docs(debt)` aparte) y cierre del plan | Todos los criterios con prueba o `Manual:` registrada; plan en `Terminado` | S |

F1 es independiente: puede entregarse y usarse antes de que exista una sola sala.

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-031-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Lista agrupada por día (F1)**

- **CA-031-1:** *Dado* un evento con actividades en tres días y algunas sin programar, *cuando* se abre la lista de Actividades, *entonces* se agrupa por día con un encabezado de fecha larga en el idioma del usuario, ordenada por día ascendente, y dentro de cada día por hora de inicio, hora de fin y título; el grupo «Sin programar» va al final.
- **CA-031-2:** *Dado* una actividad que empieza el día 20 a las 22:00 y termina el 21 a las 02:00, *cuando* se abre la lista, *entonces* aparece solo bajo el día 20 y su fila muestra la fecha de fin.
- **CA-031-3:** *Dado* más actividades que el tamaño de página y filtros activos (categoría, publicada, programada), *cuando* se pagina o se filtra, *entonces* el orden global (día y hora) se conserva y los filtros existentes siguen funcionando.
- **CA-031-4:** *Dado* la lista agrupada, *cuando* se ve a ~375 y ~1280 px con la consola abierta, *entonces* se lee sin desplazamiento horizontal de la página y sin errores.
  Manual: abrir el evento, Actividades, con datos de tres días y sin programar; a 375 y 1280 px; esperar o hacer scroll antes de concluir un fallo por animaciones de entrada.

**Salas (F2)**

- **CA-031-5:** *Dado* un organizador con `activities.manage`, *cuando* crea una sala con nombre, *entonces* queda en su evento; el nombre es obligatorio y de hasta 100 caracteres, un nombre repetido en el mismo evento (sin distinguir mayúsculas) se rechaza y el mismo nombre en otro evento se acepta.
- **CA-031-6:** *Dado* un usuario `reviewer`, un autor o uno de otra organización, *cuando* intenta ver, crear, editar u ordenar salas, *entonces* se deniega en el servidor; eliminar exige `org_admin`; y una sala de otra organización consultada por identificador responde 404 (prueba de aislamiento).
- **CA-031-7:** *Dado* una sala con actividades asignadas, *cuando* se intenta eliminar, *entonces* falla con un error que dice cuántas actividades la usan, no se borra y las actividades conservan su sala; una sala sin actividades sí se elimina.
- **CA-031-8:** *Dado* varias salas, *cuando* se reordenan, *entonces* su `position` se guarda y es el orden de las columnas de la agenda y del selector.

**Sala en la actividad y choques (F3)**

- **CA-031-9:** *Dado* una actividad programada, *cuando* se le asigna una sala del mismo evento, *entonces* se guarda; la sala es opcional; una sala de otro evento u otra organización se rechaza con error de validación; y una actividad **sin programar** no admite sala.
- **CA-031-10:** *Dado* dos actividades en la misma sala con intervalos que se cruzan, *cuando* se guarda, se edita o se mueve la segunda, *entonces* el cambio se **rechaza** con el error `ACTIVITY_ROOM_CONFLICT` que nombra la actividad en conflicto y no se escribe nada (ni la sala, ni el horario, ni los ponentes).
- **CA-031-11:** *Dado* una actividad que termina a las 10:00 en una sala, *cuando* otra empieza a las 10:00 en la misma sala, *entonces* se acepta (intervalos medio abiertos, DA-5).
- **CA-031-12:** *Dado* una actividad del día 20 de 22:00 al día 21 a las 02:00 en una sala, *cuando* otra del día 21 de 01:00 a 02:30 se programa en esa sala, *entonces* se rechaza; y una del día 21 de 02:00 a 03:00 se acepta.
- **CA-031-13:** *Dado* dos actividades con el mismo horario en salas distintas, o una sin sala, *cuando* se guardan, *entonces* se aceptan.
- **CA-031-14:** *Dado* una actividad no publicada, o oculta por reversa, con sala y horario, *cuando* otra intenta ocupar esa sala a la misma hora, *entonces* se rechaza (DA-6); una actividad eliminada no cuenta.
- **CA-031-15:** *Dado* un ponente asignado a dos actividades con horarios que se cruzan (en cualquier sala), *cuando* se guarda la segunda o se le asigna el ponente, *entonces* el cambio **se guarda** y la respuesta incluye el aviso con el nombre del ponente y de la otra actividad.
- **CA-031-16:** *Dado* el rechazo por sala ocupada, *cuando* ocurre dentro de un cambio de varios pasos (datos, categorías, ponentes, sala), *entonces* la transacción se revierte entera: no queda ninguna escritura parcial.
- **CA-031-17:** *Dado* un cambio de horario o de sala, *cuando* se ejecuta, *entonces* la comprobación y la escritura ocurren en la misma transacción con la fila del evento bloqueada (DA-10); la prueba lo verifica y registra la comprobación manual de la concurrencia real.
  Manual: dos sesiones del panel mueven a la vez dos actividades a la misma sala y hora; solo una se acepta y la otra recibe el error de choque.
- **CA-031-18:** *Dado* las Actions existentes (`CreateActivity`, `UpdateActivity`, `SyncActivitySpeakers`, `SetActivityPublication`), *cuando* se llaman con los argumentos de antes, *entonces* siguen funcionando con la misma firma y sus pruebas del plan 021 pasan sin cambios.

**Agenda (F4 y F5)**

- **CA-031-19:** *Dado* un evento con salas y actividades programadas, *cuando* se abre la pestaña Agenda, *entonces* muestra el primer día del programa con una columna por sala en el orden de `position` más «Sin sala», cada actividad ocupa las filas de su duración, la hora usa el formato (12 h o 24 h) y la zona del evento, y el selector lista los días locales del evento y los de sus actividades.
- **CA-031-20:** *Dado* un choque de ponente (o un choque de sala preexistente), *cuando* se ve la agenda y la lista, *entonces* ambas actividades llevan una insignia con texto (no solo color) y su detalle dice con cuál chocan.
- **CA-031-21:** *Dado* una actividad sin programar en el panel de pendientes, *cuando* se arrastra a una celda libre, *entonces* queda programada en ese día, esa hora de inicio y esa sala, con 60 minutos de duración; si la celda está ocupada en esa sala se rechaza con el mensaje de choque y la actividad vuelve al panel.
- **CA-031-22:** *Dado* una actividad programada de un solo día, *cuando* se arrastra a otro día, hora o sala, *entonces* conserva su duración, el servidor recalcula el fin sin confiar en el que envía el cliente, y una sala o una actividad de otro evento u organización se rechaza como inexistente.
- **CA-031-23:** *Dado* una actividad de varios días o que cruza la medianoche, *cuando* se ve la agenda, *entonces* aparece bajo su día de inicio con el indicador y **no** es arrastrable; se mueve con el formulario o con «Mover…».
- **CA-031-24:** *Dado* un usuario sin `activities.manage` o de otra organización, *cuando* intenta abrir la pestaña Agenda o mover una actividad, *entonces* se deniega en el servidor aunque conozca la dirección o llame directo al componente.
- **CA-031-25:** *Dado* un evento sin salas, sin actividades o con un error de carga, *cuando* se abre la agenda, *entonces* sin salas muestra la columna «Sin sala» y la invitación a crear salas; sin actividades muestra el estado vacío; un fallo de base se muestra como error y no como agenda vacía.
- **CA-031-26:** *Dado* cualquier actividad, *cuando* se usa la acción «Mover…» (día, hora y sala) con teclado y sin arrastrar, *entonces* ejecuta la misma `MoveActivity` con las mismas reglas y mensajes.
- **CA-031-27:** *Dado* un movimiento aceptado, *cuando* termina, *entonces* la auditoría registra la actividad, el antes y el después de fecha, hora y sala, y quién lo hizo, sin datos personales.
- **CA-031-28:** *Dado* las props públicas de una actividad y de un ponente (plan 029), *cuando* se construyen con una actividad que tiene sala, *entonces* las claves son **exactamente** las de antes y ninguna cadena contiene la sala (DA-9).
- **CA-031-29:** *Dado* un evento con 50 actividades, 8 salas y 20 ponentes, *cuando* se abre la agenda de un día, *entonces* el número de consultas no crece con el número de actividades (sin N+1; el límite se fija al medirlo en F4).
- **CA-031-30:** *Dado* la agenda y el arrastre, *cuando* se ven y se usan a ~375 y ~1280 px con la consola abierta, *entonces* la página no se desplaza horizontalmente (la cuadrícula se desplaza dentro de su contenedor), los controles tienen un área de toque de al menos 44 px, el foco es visible, el contraste cumple AA, el arrastre funciona con ratón y la acción «Mover…» cubre teclado y táctil, y la consola no muestra errores.
  Manual: abrir Actividades, pestaña Agenda, con salas y actividades de prueba; arrastrar una programada, una pendiente y una en choque; repetir con teclado y «Mover…»; a 375 y 1280 px; anotar en §9 lo observado.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| **Cambiar el comportamiento de las Actions del plan 021** (`CreateActivity`, `UpdateActivity`, `SyncActivitySpeakers`) y romper a quien las llama (plan 012, `PromoteSubmissionToProgram`) | La firma no cambia; `room_id` es una clave opcional del arreglo; CA-031-18 mantiene verdes las pruebas existentes; las Actions que crean actividades sin fecha no generan choques |
| **Fuga entre organizaciones o eventos** por una sala o consulta sin acotar | `Room` con `BelongsToOrganization`, clave foránea compuesta `(room_id, event_id, organization_id)`, validación en el servidor, CA-031-6, 22 y 24; revisión con `/revisar-aislamiento` antes de cerrar F3 y F5 |
| **Condición de carrera**: dos personas ocupan la misma sala a la vez | Comprobación y escritura en la misma transacción con el evento bloqueado (DA-10, CA-031-17); la restricción de exclusión queda como deuda (§10) |
| **El arrastre a mano resulta frágil** (táctil, desplazamiento, accesibilidad) | Prueba de concepto en F0 antes de comprometer F4 y F5; el servidor decide todo; «Mover…» es la vía accesible y táctil; sin dependencias nuevas, si no alcanza se pide permiso para una librería (acción 4) |
| **Choques entre actividades de varios días o que cruzan la medianoche** calculados mal | Un solo cálculo (`DetectScheduleConflicts`) sobre intervalos locales medio abiertos; CA-031-11 y 12 con los casos límite |
| **Hora local sin zona** y cambios de horario de verano en la zona del evento | Se calcula con la hora de pared del evento, igual que el plan 021 (DA-12); en las zonas con horario de verano una hora ambigua se trata como hora de pared. Se anota como límite conocido |
| **Las ocultas por reversa ocupan sala sin que nadie las vea** | La agenda las muestra atenuadas con «Oculto por reversa» (DA-6) y se pueden mover o eliminar |
| **Se cuele E-20 completo** (versiones, exportación, pósters, sala pública) | Fuera de alcance (§1); cada uno va a otro plan |
| **Conflicto con el plan 029 en curso** | No comparten archivos: este plan toca la pantalla del panel, las Actions y datos; el 029, la capa pública. DA-9 y CA-031-28 protegen su contrato. Esta rama sale de `staging` actualizada y se integra después de sincronizar |
| **Se adelanta E-20 respecto del plan de desarrollo (R3)** | Es decisión de Antonio (2026-11-25); R1 sigue siendo la prioridad y las fases son pequeñas y separables: F1 se puede entregar sola |
| **Agenda ilegible en móvil** (varias columnas de salas) | La cuadrícula se desplaza dentro de su contenedor; las acciones críticas tienen alternativa («Mover…», formulario); CA-031-30 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | ver `git log` | 2026-11-25 | Plan en `Aprobado`; glosario (`Room` con ámbito Evento y término «Agenda»), propuesta de datos en `docs/database-design.md` §8.4, S-11 cerrada en `docs/plan-de-desarrollo.md`. Comprobado en el código: `ManageRelatedRecords::content()` es sobrescribible y `HasTabs` existe (DA-1); `DeleteProgramCategory` borra físicamente (DA-13); `sortable.js` de Filament disponible (DA-4). **Pendiente de F0:** el `EXPLAIN` del índice de §4.1 (necesita las migraciones de F2) y la prueba de concepto del arrastre (DA-4), que se hacen antes de F4 |
| F1 | `ad0f83c` | 2026-11-25 | Rama `feat/plan-031-agenda-visual` (worktree WSL `eventos-031`, base `testing_031`). Lista de Actividades agrupada por día de inicio (encabezado de fecha larga en el idioma del usuario, «Sin programar» al final) y orden día, inicio, **fin** y título: la prueba destapó que el orden anterior no incluía la hora de fin. Sin cambios de datos ni de Actions. Pruebas primero: 4 de las 5 nuevas fallaron antes del código. `tests/Feature/Program/ActivityGroupedListTest.php`: 5 pasan (81 aserciones). Lote `tests/Feature/Program`, `LangParityTest` y `tests/Arch`: 436 pasan (1504 aserciones). Pint aplicado; PHPStan «No errors» sobre los archivos tocados; `check:criteria`: 0 errores, 12 avisos (plan 002). Cubre CA-031-1, 2 y 3. **CA-031-4 sin verificar en navegador:** Playwright estaba en uso por otra sesión y la extensión de Chrome no se conectó; queda como comprobación manual (servidor de prueba en el puerto 8098) |
| F2 | `ac9f31b` | 2026-11-25 | Salas. Migraciones `2026_11_25_100000` (`rooms`: único `(event_id, lower(name))`, posición >= 1, claves compuestas, trigger de organización) y `2026_11_25_100100` (`event_activities.room_id` con clave compuesta restrictiva y CHECK de sala solo si está programada); aplicadas y revertidas en una base local (`testing_031b`), las aplica una persona a las reales. `Room`, `RoomFactory`, `RoomPolicy` (permisos de actividades, sin permisos nuevos), `StoreRoomRequest`/`UpdateRoomRequest`, Actions `CreateRoom`, `UpdateRoom`, `DeleteRoom` (bloquea si hay actividades y dice cuántas; suelta el vínculo de las eliminadas) y `MoveRoom` (Subir y Bajar en vez del arrastre de la tabla, que saltaría permiso y auditoría), página «Salas» (`ManageEventRooms`, botón con conteo en Actividades, menú activo). Cubre CA-031-5 a 8 y la parte de esquema de CA-031-9; el resto de CA-031-9 es F3. 37 pruebas nuevas pasan (4 archivos `Room*Test`). Lote Program, Panel, Tenancy, Privacy, Events, LangParity, Arch y Unit: 1194 pasan y 2 fallan (CA-003-11 y 14 de `PrivacyNoticePanelTest`, ajenas y conocidas). PHPStan «No errors» en todo el proyecto; Pint aplicado; `check:criteria` sin errores. TDD: pruebas escritas antes del código pero no ejecutadas en rojo. Sin verificar en navegador |

## 10. Deuda que deja (a registrar en F6)

- Restricción de exclusión de PostgreSQL (`btree_gist`) como garantía de no choque de sala a nivel de base (DA-10). Prioridad P3, esfuerzo M; necesita la extensión en todos los entornos.
- Mostrar la sala en el sitio público (cambia el contrato público del plan 029 y `docs/privacidad.md` no se ve afectado). Prioridad P3, esfuerzo S.
- Versiones y publicación por versiones del programa (`Schedule`, `ScheduleBlock`), exportación (PDF, Excel, `.ics`) y pósters: resto de E-20, planes propios.
- Sedes (`Venue`) y salas compartidas entre eventos, con «copiar salas de otro evento» (DA-2). Prioridad P3, esfuerzo M.
- Redimensionar la duración arrastrando el borde, mover actividades de varios días por arrastre y deshacer. Prioridad P3, esfuerzo M.
