# feat: Landing propia de cada convocatoria, con su editor y la plantilla de convocatoria

> Plan de mejora (fuera de la hoja de ruta R0 a R5). Es la especificación SDD (ADR-0001). `NNN` = 033: todos sus criterios son `CA-033-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.
> Amplía el plan 022 (landing del evento y su editor de bloques) y reutiliza su editor, su guardado, su publicación y sus imágenes.

- **Fecha:** 2026-11-26
- **Estado:** En curso (2026-11-26: F0 a F2 construidas; falta la verificación en navegador, CA-033-16, y registrar la suite completa)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Hoy «Diseñar landing» en la fila de una convocatoria abre el editor de la landing **del evento**: es la misma pantalla para el evento y para cada una de sus convocatorias, así que no se puede dar a una convocatoria su propia página. Además, el diseño de landing de convocatoria que se aplica a todas las convocatorias con sus datos (portada con estado y cuenta regresiva, fechas, ejes, requisitos, pasos, preguntas y anuncios de otras convocatorias) no se puede editar: sus textos viven en código.

Este plan da a **cada convocatoria su propia landing**, con su editor, y convierte ese diseño en una **plantilla opcional de bloques editables**.

**Dentro:**
- Una landing propia por convocatoria (`event_landings.call_id`), con borrador y versión publicada, creada con la plantilla de convocatoria al abrir su editor por primera vez, solo en borrador.
- El editor de la convocatoria: la misma pantalla del editor del evento, sobre la landing de esa convocatoria; «Diseñar landing» en la fila de la convocatoria lleva a él.
- Seis bloques propios de convocatoria: portada, fechas clave, ejes temáticos, requisitos y formulario, pasos y anuncio de otras convocatorias; más las preguntas frecuentes, que reutilizan el bloque `faq` con el diseño de la landing de convocatoria. Lo que cambia con la convocatoria (título, descripción, fechas, estado, ejes, formulario, límite de personas, otras convocatorias) se lee de ella al mostrar la página; el bloque solo guarda los textos que el organizador personaliza, y vacíos usan el dato real o un texto de relleno.
- «Usar la plantilla de convocatoria» en la biblioteca del editor: reemplaza los bloques por la plantilla, como cambio sin guardar que «Descartar» revierte.
- La página pública de la convocatoria usa su landing propia cuando está publicada; si no, la landing automática de siempre.

**Fuera:**
- Cambiar la landing del evento ni sus bloques.
- Landing propia de la convocatoria en inglés o portugués: igual que la automática, solo se usa en español (las etiquetas fijas del diseño están en español); en otros idiomas se conserva la página anterior.
- Duplicar la landing de una convocatoria al duplicar un evento.
- Historial de versiones y edición simultánea (como el plan 022).

## 2. Punto de partida

- **Editor:** `EditEventLanding` (Filament) monta el `LandingEditor` de React y guarda con `SaveLandingDraft`, `PublishEventLanding`, `DiscardLandingDraft` y `UploadLandingImage` sobre un `EventLanding`; una sola landing por evento (`unique(event_id)`).
- **Bloques:** lista blanca `LandingBlockType` con esquema por tipo en `LandingBlockRules`; `ResolveLandingBlocks` los convierte para el público y para el lienzo; `LandingRenderer` los dibuja igual en el sitio y en el lienzo.
- **Landing automática de convocatoria:** `CallLanding` (React) armada con `CallLandingData` y los textos de `lib/call-landings.ts`; diseño de la ANPR aprobado el 24 nov 2026.
- **Base de datos:** `call_for_submissions` ya tiene `unique(id, event_id, organization_id)` (plan 011).

## 3. Decisiones

Cerradas el 2026-11-26 por Gabriel (landing propia: «sí, una landing por convocatoria»; plantilla: «bloques editables por sección»).

- DA-1: la landing de la convocatoria **comparte tabla** con la del evento: `event_landings.call_id` opcional. Dos índices únicos parciales (una del evento, una por convocatoria) y clave foránea compuesta `(call_id, event_id, organization_id)`.
- DA-2: bloques `call_*` **solo** en la landing de una convocatoria; el servidor rechaza esos tipos en la del evento.
- DA-3: **datos vivos al mostrar**: fechas, estado, ejes, formulario y otras convocatorias salen de la convocatoria, nunca se copian a la landing.
- DA-4: la plantilla nace **en borrador**; la página pública no cambia hasta «Actualizar».
- DA-5: la portada del anuncio de otra convocatoria es la imagen de **su** portada, si su landing está publicada.
- DA-6: las imágenes de la landing de una convocatoria se sirven solo si su landing está publicada y la convocatoria **no es un borrador**.
- DA-7: sin permiso nuevo: se reutiliza `events.manage` (`viewLanding`, `updateLanding`, `publishLanding`).

## 4. Contrato

- **Ruta del panel:** `/admin/{organización}/events/{evento}/calls/{convocatoria}/landing` (página `EditCallLanding`, que extiende `EditEventLanding`). Convocatoria de otro evento o identificador inválido: 404; sin `events.manage`: 403.
- **Base de datos:** migración `add_call_id_to_event_landings_table` (columna nula, no cambia datos).
- **Props del editor:** `kind` (`event` | `call`), `template` (bloques de la plantilla o `null`), `preview.call` (la convocatoria como la ve el público, logotipo, líneas vivas de requisitos, formulario y anuncios) y `translations.call_template`.
- **Prop de la página pública:** `callLanding: { blocks } | null` en `Calls/Show`.
- **Bloques:** ver `LandingBlockRules::schema` y `CallBlocksResolver`; forma pública en `resources/js/types/landing.ts`.

## 5. Permisos, aislamiento y datos personales

- Mismos permisos que el editor del evento. El filtro de organización acota la convocatoria y la landing; la convocatoria debe ser del evento de la ruta.
- Ningún bloque guarda datos personales. La página pública no recibe identificadores internos.

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Migración, modelo, acción de creación, bloques y reglas, resolvedor, editor de convocatoria, página pública y pruebas de Pest | L |
| F1 | Frontend: tipos, renderizador con la convocatoria, formularios de ajustes, biblioteca y plantilla, página pública y pruebas de Vitest | L |
| F2 | Cierre: verificación en navegador a ~375 y ~1280 px, suite completa y registro en §9 | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-033-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-033-1 · Cada convocatoria tiene su landing y su editor

- **CA-033-1:** *Dado* una convocatoria, *cuando* se abre su editor por primera vez, *entonces* se crea su propia landing, en borrador y con la plantilla de convocatoria; la landing del evento no se toca y abrirlo otra vez no crea otra.
- **CA-033-2:** *Dado* el editor de una convocatoria, *cuando* se monta, *entonces* recibe sus bloques, la plantilla, los datos vivos de la convocatoria y el enlace a su página pública.
- **CA-033-3:** *Dado* el editor del evento, *cuando* se monta, *entonces* conserva su forma de siempre: sin plantilla ni datos de convocatoria.
- **CA-033-4:** *Dado* el editor de una convocatoria, *cuando* se guarda y se publica, *entonces* cambia solo su landing y su página pública la usa.
- **CA-033-5:** *Dado* la landing del evento, *cuando* se intenta guardar un bloque de convocatoria, *entonces* se rechaza con un error en el tipo.
- **CA-033-6:** *Dado* un bloque de convocatoria con textos inválidos (una sede sin su detalle, un icono que no existe), *cuando* se guarda, *entonces* vuelve con errores por campo y no se guarda nada.
- **CA-033-7:** *Dado* una convocatoria de otro evento, un identificador que no es una convocatoria o un visitante sin sesión, *cuando* se abre el editor, *entonces* responde 404 (o redirige al acceso).
- **CA-033-8:** *Dado* un miembro sin `events.manage`, *cuando* abre el editor de una convocatoria, *entonces* se le niega.
- **CA-033-9:** *Dado* crear la landing de una convocatoria, *cuando* se repite, *entonces* devuelve la misma; y quien no tiene `events.manage` no puede crearla.

### H-033-2 · La página pública usa la landing de la convocatoria

- **CA-033-10:** *Dado* una convocatoria con landing publicada, *cuando* un visitante abre su página, *entonces* recibe sus bloques ya resueltos, con los datos vivos de la convocatoria; antes de publicar recibe la landing automática de siempre.
- **CA-033-11:** *Dado* una landing de convocatoria en borrador, *cuando* se abre la página pública, *entonces* no cambia nada, y la página del evento solo mira la landing del evento.
- **CA-033-12:** *Dado* la imagen de la portada de una landing de convocatoria, *cuando* se pide al público, *entonces* se sirve solo con la landing publicada y la convocatoria que no es un borrador.

### H-033-3 · La plantilla es opcional y editable

- **CA-033-13:** *Dado* el editor de una convocatoria, *cuando* el organizador aplica la plantilla y confirma, *entonces* los bloques se reemplazan por los de la plantilla como cambio sin guardar; si cancela, no cambia nada.
- **CA-033-14:** *Dado* la biblioteca de bloques, *cuando* es la del evento, *entonces* no ofrece las secciones de convocatoria ni la plantilla; la de una convocatoria las ofrece primero.
- **CA-033-15:** *Dado* las secciones de convocatoria, *cuando* se dibujan con su convocatoria, *entonces* muestran sus fechas, estado, ejes y formulario reales, usan el logotipo del evento si no hay portada y bloquean el botón si la convocatoria no recibe propuestas.
- **CA-033-16:** *Dado* la página pública y el editor de una convocatoria, *cuando* se abren en un teléfono (~375 px) y en un escritorio (~1280 px), *entonces* se ven completos y sin desbordes, con la consola sin errores.
  Manual: verificar en navegador a 375 y 1280 px con la consola abierta y registrar el resultado en §9.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Confundir la landing del evento con la de una convocatoria | `call_id` nulo identifica la del evento en todas las consultas (`currentPublished`, `Event::landing`, creación y editor); CA-033-1 |
| Una imagen de una convocatoria en borrador se vea en público | DA-6, CA-033-12 |
| Bloques de convocatoria en la landing del evento | DA-2, CA-033-5 |
| Datos de la convocatoria desactualizados en la landing | DA-3: se leen al mostrar |
| Migración en Railway | Es aditiva (columna nula e índices); la corre una persona con `php artisan migrate --force` |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | pendiente | 2026-11-26 | Construida; ver el resultado de las pruebas al cerrar la fase. |
| F1 | pendiente | 2026-11-26 | Construida; ver el resultado de las pruebas al cerrar la fase. |
