# feat: evento archivado o sin publicar, enlaces claros en el panel y página «no disponible» para el público

> Plan de mejora de experiencia (fuera de la hoja de ruta R0 a R5). Es la especificación SDD (ADR-0001). `NNN` = 030: todos sus criterios son `CA-030-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-25
- **Estado:** Aprobado (Antonio, 2026-11-25: especificación y DA-1 a DA-3 como se recomiendan; alcance F0 a F2)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Hoy, cuando un evento se archiva, las convocatorias de ese evento siguen mostrando en el panel «Ver página pública» y «Copiar enlace», pero la URL responde 404 sin explicación. La causa está en el código: las rutas públicas del evento empiezan con `abort_unless($event->isPublished(), 404)`, mientras que el panel decide el enlace de una convocatoria solo por el estado de la convocatoria (`status !== Draft`, `CallsRelationManager::hasPublicPage`) y no mira el estado del evento. Hay dos públicos con el mismo problema:

1. **Organizadores en el panel:** reciben un enlace muerto sin explicación.
2. **Visitantes y autores con un enlace viejo** (correos, redes sociales, confirmación de un envío): reciben un 404 genérico, sin saber qué pasó.

Este plan corrige las dos cosas: el panel deja de ofrecer enlaces que no funcionan y explica por qué, y el sitio público muestra una página clara cuando el evento fue archivado.

**Dentro:**

- Una única regla del sistema que responde si un evento o una convocatoria tienen página pública, usada por todo el panel.
- Panel: «Ver página pública» y «Copiar enlace» de las convocatorias visibles pero **deshabilitadas con una explicación** cuando el evento no está publicado (archivado o en borrador); una insignia «Evento archivado» en la lista de convocatorias; el enlace público del editor de la landing y el enlace al evento de la pantalla del autor siguen la misma regla.
- Sitio público: un evento **archivado** responde **410** con una página «Este evento ya no está disponible» (nombre del evento y de la organización, en el idioma del visitante) en sus páginas con interfaz: evento, convocatoria, formulario de envío, confirmación, programa y aviso de privacidad.
- Un evento en **borrador**, o inexistente, sigue respondiendo **404** (no se revela lo que no se publicó).
- Textos en es y en (el repositorio no tiene aún `lang/pt`).

**Fuera:**

- Archivo de solo lectura de ediciones pasadas (mantener visible el programa como «edición pasada»): decisión DA-1, se descarta por ahora; si se quiere, es un plan aparte con su revisión de privacidad.
- Cambiar cuándo o quién puede archivar un evento, o reabrir uno archivado.
- Las rutas de medios (logo, imágenes de la landing, fotos de ponentes y archivos de actividades) siguen en 404 para un evento archivado.
- Convocatorias pausadas o cerradas de un evento publicado: se quedan como hoy.
- Dar de baja enlaces ya enviados por correo: el aviso cubre el caso sin tocar los correos.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-030-1 | Como organizador, quiero que el panel no me ofrezca un enlace público que no funciona y me explique por qué, para no compartir enlaces muertos | S | plan 004 (H-004-5) |
| H-030-2 | Como visitante con un enlace viejo, quiero ver que el evento ya no está disponible, para entender qué pasó y no creer que el sitio falla | M | plan 003 (evento archivado) |

## 2. Punto de partida

Estado medido el 2026-11-25. `EventStatus` tiene `draft`, `published` y `archived`; solo un evento publicado puede archivarse (`ArchiveEvent`). Diez controladores públicos (`ShowEventController`, `ShowCallController`, `ShowSubmissionFormController`, `ShowSubmissionConfirmationController`, `ShowEventProgramController`, `ShowPrivacyNoticeController` y los de medios) exigen evento publicado y responden 404. `CallsRelationManager::hasPublicPage` solo excluye las convocatorias en borrador; `EventInfolist` ya oculta la URL pública del evento cuando no está publicado. No existe una vista de error propia: el 404 es el genérico del framework.

## 3. Decisiones

### DA-1 · Qué ve el público de un evento archivado — se recomienda **una página 410 «no disponible»**

Un evento archivado responde 410 (Gone: existió y se retiró) con una página breve: el nombre del evento y de la organización y el aviso, sin ningún dato del evento (ni fechas, ni programa, ni formulario, ni botón de envío). Como el evento ya fue público, mostrar su nombre no revela nada nuevo. Un borrador o un evento inexistente siguen en 404 para no revelar contenido sin publicar. Alternativa descartada por ahora: **archivo de solo lectura** (programa visible como «edición pasada»): es más trabajo y deja datos públicos de ediciones pasadas indefinidamente, lo que exige revisar `docs/privacidad.md`.

### DA-2 · El enlace en el panel — se recomienda **deshabilitado con explicación**

Se muestra la acción pero deshabilitada, con una descripción («El evento está archivado: sus páginas públicas ya no se muestran»). Alternativa descartada: ocultarla, porque el organizador no sabría por qué desapareció.

### DA-3 · Eventos en borrador — se recomienda **el mismo aviso en el panel, con otro texto; el público sigue en 404**

En el panel una convocatoria de un evento en borrador muestra la acción deshabilitada con «El evento aún no está publicado». En el sitio público el borrador no cambia: 404.

## 4. Contrato

- **Sin rutas JSON ni entradas nuevas en `docs/api/endpoints.md`.** Es interfaz: páginas públicas (Inertia) y acciones del panel (Filament).
- **Regla única:** una clase de `app/Support/` (nombre a elegir por quien implemente, p. ej. `PublicPageAvailability`) que, dado un evento (y opcionalmente una convocatoria), responde si hay página pública y, si no, **por qué** (`archived`, `draft_event`, `draft_call`). La usan el panel y las páginas públicas.
- **Sitio público:** un único punto de decisión (middleware o servicio compartido) aplicado a las rutas de página del evento. Renderiza la página Inertia «no disponible» con estado 410 y la cabecera `X-Robots-Tag: noindex`.
- **Frontend:** una página de Inertia nueva con los componentes del catálogo (`design.md` §1.4), mobile-first, con los estados y el texto por i18n.
- **Sin cambios de datos:** no hay migraciones, modelos ni permisos nuevos.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | No cambian: quien ve la lista de convocatorias ya ve sus acciones; este plan solo cambia si se ofrecen habilitadas o deshabilitadas |
| ¿Qué Policy cubre cada acción y dónde se prueba? | Ninguna nueva. Las acciones de la lista conservan su autorización actual |
| ¿Hay modelos nuevos con datos de una organización? | No |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. La regla solo lee el estado del evento de la propia ruta, acotado por la organización de la ruta (`scopeBindings`): un evento o una convocatoria de otra organización responde 404 |
| ¿Se guardan datos personales nuevos? | No. La página «no disponible» muestra solo el nombre del evento y de la organización, que ya eran públicos |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | Nada nuevo |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | La regla única y su prueba unitaria; el panel la usa: acciones deshabilitadas con explicación, insignia «Evento archivado», enlace de la landing y de la pantalla del autor (H-030-1) | S |
| F1 | El sitio público: punto de decisión compartido, página Inertia «no disponible» con 410, textos es y en, pruebas de Pest y de Vitest (H-030-2) | M |
| F2 | Cierre: la guardiana que impide volver a decidir enlaces públicos a mano, verificación en navegador a ~375 y ~1280 px, registro en §9 | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-030-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-030-1 · El panel no ofrece enlaces muertos

- **CA-030-1:** *Dado* un evento publicado y una convocatoria publicada, *cuando* el organizador abre la lista de convocatorias, *entonces* «Ver página pública» y «Copiar enlace» están habilitadas y apuntan a la URL pública de siempre.
- **CA-030-2:** *Dado* un evento archivado con una convocatoria publicada, *cuando* el organizador abre la lista, *entonces* «Ver página pública» y «Copiar enlace» aparecen deshabilitadas, sin URL a la que navegar, y su descripción explica que el evento está archivado y que sus páginas públicas ya no se muestran.
- **CA-030-3:** *Dado* un evento en borrador con una convocatoria publicada, *cuando* el organizador abre la lista, *entonces* las dos acciones aparecen deshabilitadas con la explicación «El evento aún no está publicado».
- **CA-030-4:** *Dado* una convocatoria en borrador de un evento publicado, *cuando* el organizador abre la lista, *entonces* no se ofrece ningún enlace público, como hoy (CA-004-38).
- **CA-030-5:** *Dado* un evento archivado, *cuando* el organizador abre su lista de convocatorias, *entonces* ve la insignia «Evento archivado»; con el evento publicado no aparece.
- **CA-030-6:** *Dado* un evento que no está publicado, *cuando* se muestra el editor de la landing o la pantalla de acceso del autor, *entonces* no se ofrece el enlace público del evento.
- **CA-030-7:** *Dado* cada estado posible (evento publicado, archivado y en borrador; convocatoria publicada, pausada, cerrada y en borrador), *cuando* se consulta la regla única, *entonces* responde si hay página pública y el motivo cuando no la hay, y el panel y las páginas públicas usan esa misma respuesta.

### H-030-2 · El público ve un aviso claro

- **CA-030-8:** *Dado* un evento archivado, *cuando* un visitante abre la página del evento, la de una convocatoria, el formulario de envío, la confirmación de un envío, el programa o el aviso de privacidad, *entonces* la respuesta es 410 con la página «Este evento ya no está disponible», que muestra solo el nombre del evento y el de la organización.
- **CA-030-9:** *Dado* un evento archivado, *cuando* se abre cualquiera de esas páginas, *entonces* la respuesta no contiene ningún dato del evento más allá de su nombre y el de la organización (ni fechas, ni programa, ni convocatorias, ni formulario ni enlace de envío).
- **CA-030-10:** *Dado* un evento en borrador, *cuando* un visitante abre cualquiera de esas páginas, *entonces* la respuesta sigue siendo 404 y no revela nada.
- **CA-030-11:** *Dado* un evento o una convocatoria que no existe, o que pertenece a otra organización, *cuando* se abre su página pública, *entonces* la respuesta es 404, como hoy.
- **CA-030-12:** *Dado* un evento archivado, *cuando* alguien intenta enviar una propuesta a una de sus convocatorias, *entonces* la propuesta no se crea y la respuesta no revela datos del evento.
- **CA-030-13:** *Dado* un evento archivado, *cuando* se piden su logo, las imágenes de su landing, las fotos de sus ponentes o los archivos de sus actividades, *entonces* la respuesta sigue siendo 404.
- **CA-030-14:** *Dado* un evento publicado con una convocatoria pausada o cerrada, *cuando* un visitante abre su página, *entonces* se muestra como hoy, con el estado de la convocatoria.
- **CA-030-15:** *Dado* un visitante con `?lang=` o con preferencia de idioma del navegador, *cuando* abre la página «no disponible», *entonces* el texto sale en ese idioma si es es o en, y con el idioma base del sistema si no.
- **CA-030-16:** *Dado* la página «no disponible», *cuando* se solicita, *entonces* lleva la cabecera `X-Robots-Tag: noindex` y no se guarda en caché compartida.
- **CA-030-17:** *Dado* la página «no disponible», *cuando* se abre en un teléfono (~375 px) y en un escritorio (~1280 px), *entonces* se ve completa, legible y sin desbordes, con la consola sin errores y accesibilidad AA (foco, contraste, jerarquía de títulos).
  Manual: verificar en navegador a 375 y 1280 px con la consola abierta y registrar el resultado en §9.
- **CA-030-18:** *Dado* los textos nuevos, *cuando* se compara `lang/es` con `lang/en`, *entonces* tienen las mismas claves, y ningún texto visible está escrito a mano en el código.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Revelar con el 410 que existió un borrador o un evento ajeno | Solo un evento **archivado** de la organización de la ruta responde 410; el borrador y lo inexistente siguen en 404 (CA-030-10 y 11) |
| Dejar datos del evento en la página «no disponible» | La página recibe solo el nombre del evento y de la organización; CA-030-9 lo prueba buscando fechas, programa y enlaces |
| Una ruta pública nueva olvida el punto de decisión y vuelve el 404 mudo | Guardiana en F2: toda ruta de página de `events/{event}` pasa por el punto compartido |
| Cambiar el comportamiento de las convocatorias pausadas o cerradas | CA-030-14 las deja como hoy |
| El panel y el público deciden distinto | Una única regla (CA-030-7) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | `b115eed` | 2026-11-25 | Regla única `App\Support\Events\PublicPageAvailability` (+ enum `PublicPageUnavailability`; incluye `eventUrl()` para los enlaces al evento). El panel la usa: «Ver» y «Copiar enlace» deshabilitadas, sin URL y con tooltip explicativo (evento archivado o sin publicar); borrador de convocatoria sigue oculto (CA-004-38); la insignia «Evento archivado» se muestra en la **pestaña** de convocatorias (`getBadge` del gestor de relación), no por fila; el enlace público del editor de la landing y el de vuelta al evento en las pantallas del autor valen `null` sin página pública (el frontend los oculta). Pest: 82 pruebas enfocadas verdes (`PublicPageAvailabilityTest` con los 12 cruces de estado, `CallPanelTest`, `LandingEditorPageTest`, `AccessLinkTest`); Arch/Unit/Events/Calls/Landings/Tenancy/Catalog: 849 verdes (2 rojas por el cambio 404→410 de F1, esperado); Pint y Larastan sin errores; `typecheck`, `lint` y Vitest (45 archivos, 334 pruebas) verdes. Se ajustaron CA-004-37/38/40 y CA-022-36 para publicar el evento antes de comprobar el enlace. |
