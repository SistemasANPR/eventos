# feat: Landing del evento, con sus convocatorias, y editor visual de bloques

> Plan 022. Especificación (SDD, ADR-0001). El nombre del archivo conserva «landing-de-convocatoria» para no romper referencias; desde la revisión del 2026-11-19 la landing es **del evento**.

- **Fecha:** 2026-11-18 (revisado el 2026-11-19)
- **Estado:** En curso (2026-11-23: F0 a F4 construidas y registradas; F5 y F6 pendientes: editor visual, segunda parte, y cierre)
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna directa. Se apoya en la excepción de React dentro del panel (ADR-0010) y en el disco `media` del plan 021 (DA-11).

## 1. Objetivo y alcance

Hoy la página pública de un evento (`/{organización}/events/{evento}`) es una plantilla fija: nombre, descripción, fechas, sede y aviso de privacidad. No muestra las convocatorias ni su registro, y el organizador no puede cambiar su aspecto ni añadir contenido propio.

Cada vez que se **crea un evento** nace con él su **landing**, ya armada con una plantilla: portada, datos del evento y las **convocatorias del evento con su botón de registro**. El organizador la edita en un **editor visual** inspirado en Elementor, pensado para ser intuitivo: a un lado la página tal como la verá el visitante y al otro un panel de ajustes; hace clic en un bloque, lo ajusta y ve el cambio al instante. Al pulsar **«Actualizar»** los cambios se guardan y se ven en la página pública; también puede **«Guardar borrador»** sin publicar. Es un parecido a Elementor para que sea fácil de usar, no una copia completa.

**Dentro:**
- Una landing por evento, con borrador y versión publicada, creada con la plantilla al crear el evento.
- Catálogo cerrado de bloques (lista blanca), con textos por idioma del evento.
- Bloque `calls`: las convocatorias publicadas del evento, con estado, fechas y botón de registro, calculados al mostrar la página.
- Editor visual de dos zonas (lienzo y panel de ajustes) en una pantalla propia del panel, que muestra la página con el mismo renderizador React del sitio público.
- Entradas al editor: «Diseñar landing» en la pantalla del evento, en el menú de su tarjeta y desde la fila de cada convocatoria.
- Guardar borrador, actualizar (guardar y publicar), descartar cambios y ver la página.
- Imágenes subidas por el organizador, en disco privado y servidas por la aplicación.
- La página actual del evento sigue funcionando para eventos sin landing publicada.

**Fuera:**
- Arrastrar y soltar con el ratón y edición del texto sobre la página: los bloques se mueven con botones y teclado (DA-2).
- HTML, CSS o JavaScript propios, `iframe` y videos incrustados.
- Sitio de varias páginas, marca blanca y colores libres (ADR-0004, DA-4).
- Historial de versiones, deshacer y rehacer, edición simultánea.
- SSR (sigue diferido, RC-5).
- Bloques de módulos que aún no existen (patrocinadores, mapa, boletos, ponentes).
- La página de la convocatoria (`calls.show`) no cambia.

## 2. Punto de partida

Medido el 2026-11-19 sobre `staging`:

- **Página del evento:** `ShowEventController` entrega `Events/Show` (`resources/js/pages/Events/Show.tsx`, 24 líneas) con `EventHero` (48 líneas) y `PublicEventData`: nombre y descripción traducibles, fechas con la zona y el formato de hora del evento, sede, ciudad, idiomas y aviso de privacidad. Un evento sin publicar, archivado o de otra organización responde 404. No lista convocatorias.
- **Página de la convocatoria:** `Calls/Show` con estado, fechas, ejes y botón de envío, ya terminada (plan 004). No se toca.
- **Creación del evento:** `CreateEvent` crea el evento en borrador dentro de una transacción, siembra los tipos de propuesta y registra la auditoría. Ahí se crea la landing.
- **Permisos:** `events.manage` y `events.publish` existen en `RolePermissions`. No hay permiso de landing.
- **Patrones reutilizables:** `jsonb` validado con clase de esquema (ADR-0010); React montado dentro del panel con el mismo componente del sitio público (ADR-0010, excepción acotada); `RichTextSanitizer` (plan 021); disco `media` y `StoreProgramImage` (plan 021); componentes del catálogo (`EventHero`, `RichText`, `TextLink`).
- **Panel:** la vista del evento (`ViewEvent`) ofrece Editar, Publicar y Archivar; las convocatorias están en `CallsRelationManager` con «Editar» y «Publicar» por fila; la tarjeta del evento tiene menú (plan 024).
- **Nombres:** el glosario usa «Bloque» para `ScheduleBlock`; el bloque de la landing es `LandingBlock` (DA-13).
- **Seguridad:** `docs/seguridad.md` pide sanear el texto con formato en el servidor, no servir `.svg` con scripts ni `.html`, y ajustar la CSP a los scripts reales.

## 3. Decisiones

Todas cerradas el 2026-11-19 por Gabriel (delegación de AGENTS.md), con la recomendación indicada.

- DA-1: la landing es **del evento** y muestra sus convocatorias.
- DA-2: editor visual de **dos zonas**, sin arrastre con ratón.
- DA-3: **9 bloques** de lista blanca.
- DA-4: `jsonb` validado, **borrador y versión publicada**.
- DA-5: textos **por idioma del evento**.
- DA-6: se **crea con plantilla al crear el evento**, ya publicada; la visibilidad la decide el estado del evento.
- DA-7: imágenes en el disco `media`, servidas **solo si están en la versión publicada**.
- DA-8: texto enriquecido con **`RichTextSanitizer`** y enlaces `https`.
- DA-9: **sin SSR**.
- DA-10: se reutiliza **`events.manage`**.
- DA-11: duplicar el evento copia la landing **en borrador**.
- DA-12: **«Actualizar»** guarda y publica; **«Guardar borrador»** solo guarda.
- DA-13: nombres `EventLanding` y «bloque de la landing».
- DA-14: **límites** de tamaño.
- DA-15: aspecto solo con **tokens** predefinidos.
- DA-16: **momento**: ahora, a petición de Gabriel (antes se había diferido a después de R0).
- DA-17: convocatoria **cerrada**: el enlace se mantiene y el botón de registro queda **bloqueado**.

### DA-1 · Alcance — se recomienda **una landing por evento, que lista sus convocatorias**
Se pidió que al generar un evento aparezca su landing con el evento y los registros de la convocatoria. La URL pública del evento ya existe y no cambia. Las convocatorias (estado, fechas y botón de registro) se leen al mostrar la página: la landing nunca guarda una copia, así que no se desactualiza. La página de cada convocatoria sigue como está. Alternativa descartada: una landing por convocatoria; no cumple lo pedido (evento y registros juntos) y obliga a mantener varias páginas por evento.

### DA-2 · Editor — se recomienda **editor visual de dos zonas, sin arrastre con ratón**
Pantalla propia en el panel: **lienzo** (la página real, con el mismo `LandingRenderer` del sitio público) y **panel de ajustes**. Se hace clic (o Enter) en un bloque para seleccionarlo y su formulario aparece en el panel; hay una biblioteca para añadir bloques; botones y teclado para mover, duplicar y quitar; interruptor de móvil, tablet y escritorio; barra superior con «Guardar borrador», «Actualizar», «Descartar» y «Ver landing». Se apoya en el patrón del ADR-0010 (React montado en una página del panel); el estado del editor lo guardan acciones de Livewire llamadas con `$wire`, sin rutas JSON nuevas. Alternativas descartadas: arrastrar y soltar con edición de texto sobre la página (exige una librería no autorizada, y es mucho más esfuerzo y riesgo de accesibilidad); un formulario de bloques con vista previa aparte (no se siente como un editor visual).

### DA-3 · Bloques — se recomienda **9 bloques de lista blanca**
`hero` (título, subtítulo, imagen de fondo y botón con enlace `https`), `rich_text` (texto con formato), `event_info` (nombre, fechas con el formato de hora, sede y ciudad, tomados del evento), `calls` (las convocatorias publicadas del evento con estado, fechas y botón de registro), `tracks` (ejes activos del evento), `faq` (preguntas y respuestas), `image_gallery` (hasta 12 imágenes con texto alternativo), `spacer` y `columns` (dos columnas de texto o imagen). Los bloques `event_info`, `calls` y `tracks` no guardan copia de los datos. Cada bloque tiene identificador generado por el servidor, un tipo de la lista blanca y un contenido validado por su propia clase. Plantilla predeterminada: `hero`, `event_info`, `calls` y `tracks`.

### DA-4 · Almacenamiento — se recomienda **`jsonb` validado, borrador y versión publicada**
Tabla `event_landings` (una fila por evento) con `draft_blocks` y `published_blocks` en `jsonb`, `version` y `published_at`. La forma de cada bloque se valida con una clase de esquema con pruebas (la base solo comprueba que sea un arreglo y su tamaño). Alternativas descartadas: una fila por bloque (EAV) y una columna en `events` (mezcla el evento con presentación).

### DA-5 · Idiomas — se recomienda **textos por idioma del evento**
Cada texto es un objeto por idioma de `event.locales`; el editor muestra una pestaña por idioma y marca lo que falta. Si falta un idioma se muestra el idioma base. El texto alternativo de las imágenes también es por idioma. Las etiquetas fijas salen de `lang/`.

### DA-6 · Nacimiento — se recomienda **plantilla al crear el evento, ya publicada**
`CreateEvent` crea la landing en la misma transacción, con la plantilla en `draft_blocks` y en `published_blocks`, y registra la auditoría. La visibilidad pública la decide el estado del evento (borrador: 404, igual que hoy); al publicar el evento ya se ve la landing. Los eventos existentes no se tocan: su landing se crea, con la plantilla, al abrir el editor por primera vez, y hasta entonces se ve la página actual.

### DA-7 · Imágenes — se recomienda **disco `media`, servidas solo si están publicadas**
Mismo criterio que `StoreProgramImage`: JPEG, PNG y WebP validados por contenido, 5 MB y 40 millones de píxeles, nombre generado, sin SVG, ruta `organizations/{id}/events/{id}/media/landing/`. Se registran en `landing_images` y los bloques guardan solo el identificador. Una ruta pública sirve una imagen solo si el evento y la landing están publicados y la imagen figura en `published_blocks`. El editor las ve por una ruta autenticada.

### DA-8 · Contenido — se recomienda **`RichTextSanitizer` y solo `https`**
Todo texto con formato pasa por `RichTextSanitizer::clean()` al guardar. Los botones y enlaces aceptan `https` externos, nunca `javascript:`, `data:` ni `http`, y llevan `rel="noopener noreferrer nofollow"`. El renderizador no usa `dangerouslySetInnerHTML` salvo con HTML ya saneado.

### DA-9 · SSR — se recomienda **mantenerlo diferido (RC-5)**
Se renderiza con Inertia como hoy, con `<title>`, descripción y `og:image` del bloque `hero`.

### DA-10 · Permisos — se recomienda **reutilizar `events.manage`**
Ver el borrador y editar, guardar, actualizar y descartar exigen `events.manage`; la landing publicada la ve cualquiera. No hay permiso nuevo ni cambio en la matriz de roles (acción 9 de `AGENTS.md`).

### DA-11 · Duplicar el evento — se recomienda **copiar la landing en borrador**
Cuando el plan 019 duplique un evento, copia la landing como borrador, sin versión publicada, y duplica las imágenes con identificadores y rutas nuevas. Hasta entonces este plan no depende de él.

### DA-12 · Guardar y publicar — se recomienda **«Actualizar» y «Guardar borrador»**
«Actualizar» guarda y publica en una sola operación, para que los cambios se vean en la página pública (como el botón «Actualizar» de Elementor). «Guardar borrador» solo guarda. Un guardado con una `version` anterior se rechaza con conflicto. No hay historial; la auditoría registra quién publicó y cuándo.

### DA-13 · Nombres — se recomienda **`EventLanding` y «bloque de la landing»**
Modelo `EventLanding` (tabla `event_landings`), `LandingBlock` y enum `LandingBlockType`. Se añaden al glosario.

### DA-14 · Límites — se recomienda **30 bloques, 128 KB, 20 imágenes**
Máximo 30 bloques, 128 KB de `draft_blocks`, 20 imágenes por landing, 12 por galería, 20 preguntas por `faq` y 5 000 caracteres por texto enriquecido.

### DA-15 · Aspecto — se recomienda **variantes sobre tokens**
El organizador elige variantes (fondo claro o acento, alineación, ancho), no colores ni CSS. El contraste AA sale por construcción de los tokens y de `lib/classes.ts`.

### DA-16 · Momento — **ahora**
El plan maestro dejó la landing fuera de R0; por petición de Gabriel (2026-11-19) se implementa ahora. Antes de usarse en producción hay que aplicar las migraciones nuevas en la base de Railway (acción de una persona).

### DA-17 · Convocatoria cerrada — **enlace estable y botón bloqueado**
Si una convocatoria se pausa o se cierra, la landing sigue publicada, su enlace se mantiene y el botón de registro se muestra **bloqueado** (deshabilitado, sin enlace, con el estado visible). El servidor lo hace cumplir aparte: un envío con la convocatoria cerrada se rechaza con `CALL_NOT_OPEN` (plan 006).

## 4. Contrato

**Rutas del panel** (Filament, bajo el evento; no son JSON): la pantalla del editor de la landing del evento y su subida de imágenes. Se llega con la acción «Diseñar landing» de `ViewEvent`, del menú de la tarjeta del evento y de la fila de cada convocatoria. Guardar, actualizar, descartar y subir imágenes son acciones de Livewire llamadas desde el editor con `$wire` (POST con CSRF); no hay rutas JSON nuevas.

**Rutas públicas:**
- `GET /{organization}/events/{event}` (`events.show`): sin cambio de URL; con landing publicada entrega además `landing.blocks` ya resueltos.
- `GET /{organization}/events/{event}/landing/images/{image}` (`public.landings.image`): sirve una imagen de la versión publicada; 404 si no hay landing publicada, si la imagen no está en `published_blocks`, si es de otro evento u organización o si el evento no está publicado. Cabecera `X-Content-Type-Options: nosniff`. No es JSON; se documenta en `docs/api/endpoints.md`.

**Props** (`resources/js/types/landing.ts`, a mano): unión discriminada `PublicLandingBlock` por `type`, ya resuelta en el servidor (fechas y convocatorias calculadas, URLs de imágenes construidas, textos en el idioma pedido). Para `calls`: título, tipo, estado público, fechas y `registerUrl` (nulo si la convocatoria no está abierta). La página no recibe el borrador, identificadores internos de organización ni datos del equipo (extiende CA-002-15).

**Validación:** clase de esquema `LandingBlockRules` (reglas por tipo) usada por las Actions; Form Request propio para la subida de imágenes.

**Acciones** (`app/Actions/Landings/`): `CreateEventLanding`, `SaveLandingDraft`, `PublishEventLanding`, `DiscardLandingDraft`, `UploadLandingImage`, `DeleteLandingImage`, `ResolveLandingBlocks`.

**Componentes:** `LandingRenderer` y un componente por tipo de bloque, registrados en `catalog/registry.ts` con demo en `/components` (`design.md` §1.4); el editor (`LandingEditor`, `EditorCanvas`, `BlockLibrary`, `BlockSettingsPanel`) monta `LandingRenderer` como lienzo.

### 4.1 Propuesta de datos (`docs/database-design.md` §6)

1. **Necesidad:** el contenido de presentación de un evento. Dueño: módulo de eventos. Ciclo de vida: nace con el evento, se edita en borrador, se publica y se elimina con él.
2. **Inspección:** `events` (sin campos de landing), `form_definitions` (patrón `jsonb` con versión), tablas de archivos de los planes 007 y 021, `activity_log`.
3. **Modelo propuesto:**
   - `event_landings`: `id uuid`, `organization_id uuid NOT NULL`, `event_id uuid NOT NULL UNIQUE`, `draft_blocks jsonb NOT NULL`, `published_blocks jsonb NULL`, `version integer NOT NULL DEFAULT 1`, `published_at timestamptz NULL`, `published_by uuid NULL`, `created_at`, `updated_at`, `deleted_at`. Ámbito: evento.
   - `landing_images`: `id uuid`, `organization_id`, `event_id`, `event_landing_id`, `path`, `mime`, `size_bytes`, `width`, `height`, `created_at`, `deleted_at`. Ámbito: evento.
4. **Integridad:** claves foráneas compuestas `(event_id, organization_id)` y `(event_landing_id, organization_id)`; `CHECK` de que ambos `jsonb` son arreglos y de su tamaño; `CHECK` de que `published_at` y `published_blocks` van juntos; `SoftDeletes`; el modelo usa `BelongsToOrganization`.
5. **Cambio:** dos migraciones nuevas, sin tocar las existentes y sin datos que migrar. **La aplica una persona en una base real** (en Railway: `php artisan migrate --force`). Verificación: inserción cruzada, aislamiento y reversa.
6. **Alternativa descartada:** ver DA-4.
7. **Preguntas de negocio:** ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Ver el borrador, editar, guardar, actualizar y descartar: `events.manage`. El visitante solo ve la versión publicada. Sin cambios en la matriz de roles (DA-10) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | Se amplía la Policy del evento con `viewLanding`, `updateLanding` y `publishLanding`, con una prueba por rol (permitido y denegado). Cada Action autoriza con `authorizeActor` |
| ¿Modelos nuevos con datos de una organización? | Sí: `EventLanding` y `LandingImage`, con `BelongsToOrganization` y entrada en `tests/Feature/Tenancy/` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Como los eventos: el acceso a un evento concreto lo decide la Policy. Un identificador de otra organización o evento responde 404 |
| ¿Datos personales nuevos? | No los pide la plataforma. Un organizador podría escribir nombres o fotos de personas en un bloque: es contenido público que él decide publicar. Se avisa en el editor y se documenta en `docs/privacidad.md` |
| ¿Se suben archivos? | Sí: JPEG, PNG y WebP, hasta 5 MB y 40 millones de píxeles, disco `media` privado, nombre generado, validación por contenido, sin SVG |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | `landing_created`, `landing_published`, `landing_draft_discarded` e `landing_image_uploaded` o `deleted`, con identificadores y sin contenido |

## 6. Estrategia por fases

Cada fase deja el repositorio en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Esquema de bloques: enum `LandingBlockType`, clases de reglas, plantilla, tipos TypeScript, glosario y `docs/api/endpoints.md`. Sin comportamiento visible | S |
| F1 | Datos: migraciones, modelos, factories, Policy y pruebas de aislamiento | M |
| F2 | Acciones: crear con plantilla en `CreateEvent`, crear al abrir el editor, guardar borrador, actualizar, descartar y subir imágenes, con auditoría | M |
| F3 | Sitio público: `LandingRenderer` y los 9 bloques, `ShowEventController` con respaldo a la página actual y la ruta de imágenes | M |
| F4 | Editor visual, primera parte: pantalla con lienzo y panel de ajustes, entradas desde el evento, su tarjeta y las convocatorias, selección con clic y formulario por bloque con pestañas de idioma | L |
| F5 | Editor visual, segunda parte: biblioteca para añadir bloques, mover, duplicar y quitar con botones y teclado, interruptor móvil, tablet y escritorio, «Guardar borrador», «Actualizar» y «Descartar» | L |
| F6 | Accesibilidad AA, verificación en navegador a ~375 y ~1280 px y copia al duplicar el evento (cuando exista el plan 019) | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-022-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Datos y creación**

- **CA-022-1:** *Dado* un usuario con `events.manage`, *cuando* crea un evento, *entonces* se crea su landing con los bloques de la plantilla (`hero`, `event_info`, `calls`, `tracks`) en el borrador y en la versión publicada, en la misma transacción; si falla, no queda el evento a medias.
- **CA-022-2:** *Dado* un evento anterior a este plan, *cuando* el organizador abre el editor por primera vez, *entonces* se crea su landing con la plantilla y la página pública no cambia hasta que la actualice.
- **CA-022-3:** *Dado* la base de datos, *cuando* se inserta una landing o una imagen con el `organization_id` de A y el evento de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada).
- **CA-022-4:** *Dado* un administrador de A, *cuando* abre, guarda, actualiza o pide la imagen de la landing de un evento de B, *entonces* responde 404 y no cambia nada.
- **CA-022-5:** *Dado* `EventLanding` y `LandingImage`, *cuando* corren las guardianas de tenancy, *entonces* ambos usan `BelongsToOrganization` y sin contexto la consulta lanza `MissingOrganizationContext`.

**Permisos**

- **CA-022-6:** *Dado* un usuario con `events.manage`, *cuando* guarda, actualiza o descarta, *entonces* la operación se realiza; sin ese permiso recibe 403 y no se guarda nada.
- **CA-022-7:** *Dado* un rol sin `events.manage` que llama a la Action o a la ruta de subida directamente, *cuando* lo intenta, *entonces* recibe 403 (la interfaz oculta el botón, pero el servidor decide).
- **CA-022-8:** *Dado* un visitante o un autor con sesión, *cuando* pide el borrador, el editor o una imagen no publicada, *entonces* recibe 404 o 403 sin revelar el contenido.

**Bloques y validación**

- **CA-022-9:** *Dado* un bloque con un tipo fuera de la lista blanca o con campos que su tipo no admite, *cuando* se guarda, *entonces* se rechaza con error por campo y no se guarda ninguno.
- **CA-022-10:** *Dado* más de 30 bloques, más de 128 KB, más de 20 imágenes, una galería de más de 12 o un `faq` de más de 20 preguntas, *cuando* se guarda, *entonces* se rechaza indicando el límite.
- **CA-022-11:** *Dado* un texto enriquecido con `<script>`, `<iframe>`, `<svg>`, atributos `on*`, `javascript:` o imágenes embebidas, *cuando* se guarda, *entonces* queda saneado con la lista blanca y no aparece en la página pública.
- **CA-022-12:** *Dado* un botón o enlace con destino `javascript:`, `data:` o `http`, *cuando* se guarda, *entonces* se rechaza; un `https` externo se acepta y se renderiza con `rel="noopener noreferrer nofollow"`.
- **CA-022-13:** *Dado* un bloque de imagen sin texto alternativo en el idioma base, *cuando* se guarda, *entonces* se rechaza.
- **CA-022-14:** *Dado* los identificadores de los bloques, *cuando* llegan del cliente, *entonces* el servidor los genera y los fija; uno enviado por el cliente se ignora.
- **CA-022-15:** *Dado* dos guardados con la misma `version` de partida, *cuando* el segundo llega después del primero, *entonces* se rechaza con conflicto y no pisa el primero.

**Guardar y publicar**

- **CA-022-16:** *Dado* un borrador válido, *cuando* el organizador pulsa «Actualizar», *entonces* `published_blocks` es una copia del borrador, la `version` aumenta, queda `published_at` y quién publicó, se registra `landing_published` sin contenido y la página pública ya lo muestra.
- **CA-022-17:** *Dado* un borrador guardado con «Guardar borrador», *cuando* un visitante pide la página, *entonces* ve la última versión publicada y no los cambios.
- **CA-022-18:** *Dado* un borrador con cambios, *cuando* el organizador descarta, *entonces* el borrador vuelve a ser igual a la versión publicada y se registra `landing_draft_discarded`.
- **CA-022-19:** *Dado* una actualización repetida sin cambios, *cuando* se vuelve a ejecutar, *entonces* no duplica la auditoría ni aumenta la `version`.
- **CA-022-20:** *Dado* un fallo a mitad de actualizar, *cuando* ocurre, *entonces* la transacción se revierte y la versión publicada anterior queda intacta.

**Sitio público**

- **CA-022-21:** *Dado* un evento publicado con landing publicada, *cuando* un visitante pide su URL, *entonces* ve los bloques en el orden guardado, en el idioma pedido (o el base si falta) y con las fechas en la zona y el formato de hora del evento.
- **CA-022-22:** *Dado* un evento sin landing publicada, *cuando* un visitante pide su URL, *entonces* ve la página actual idéntica a la de hoy (CA-002-15 sigue pasando).
- **CA-022-23:** *Dado* un evento en borrador, archivado, de otra organización o inexistente, *cuando* se pide su URL, *entonces* responde 404 igual que hoy, tenga o no landing.
- **CA-022-24:** *Dado* el bloque `calls`, *cuando* cambian las fechas o el estado de una convocatoria, *entonces* la página lo refleja sin actualizar la landing; solo aparecen las convocatorias publicadas del evento, nunca borradores ni de otro evento.
- **CA-022-25:** *Dado* el bloque `calls` con una convocatoria abierta, *cuando* se renderiza, *entonces* su botón de registro enlaza al formulario de envío.
- **CA-022-26:** *Dado* una convocatoria programada, pausada o cerrada, *cuando* se renderiza el bloque `calls`, *entonces* el botón de registro se muestra **bloqueado** (deshabilitado, sin enlace, con el estado visible y accesible para un lector de pantalla), el enlace directo al formulario no permite enviar (`CALL_NOT_OPEN`) y la URL de la landing se mantiene.
- **CA-022-27:** *Dado* las props de la página, *cuando* se renderiza, *entonces* no incluyen el borrador, identificadores internos de organización ni datos del equipo.
- **CA-022-28:** *Dado* la landing publicada, *cuando* se renderiza, *entonces* el `<title>`, la descripción y `og:image` salen del bloque `hero` y del evento.

**Imágenes**

- **CA-022-29:** *Dado* un archivo cuyo contenido no es JPEG, PNG ni WebP (SVG, HTML, ejecutable o imagen con código incrustado), *cuando* se sube, *entonces* se rechaza por el contenido real aunque el nombre o el tipo declarado sean válidos, y no queda nada en el disco.
- **CA-022-30:** *Dado* una imagen de más de 5 MB o de más de 40 millones de píxeles, *cuando* se sube, *entonces* se rechaza.
- **CA-022-31:** *Dado* una imagen subida, *cuando* se guarda, *entonces* el nombre en disco lo genera el servidor, vive bajo la ruta de su organización y evento, y los bloques guardan su identificador, no su ruta.
- **CA-022-32:** *Dado* una imagen que solo está en el borrador, *cuando* un visitante pide su URL, *entonces* responde 404; una presente en `published_blocks` se sirve con `nosniff`.
- **CA-022-33:** *Dado* una imagen quitada de la landing, *cuando* se actualiza, *entonces* deja de servirse al público y se marca como eliminada.

**Editor**

- **CA-022-34:** *Dado* un evento, *cuando* un usuario con `events.manage` lo ve, *entonces* hay «Diseñar landing» en su pantalla, en el menú de su tarjeta y en la fila de cada convocatoria, y las tres llevan a la misma pantalla; sin el permiso no se ofrece y la ruta directa responde 403.
- **CA-022-35:** *Dado* el lienzo del editor, *cuando* se alterna entre móvil, tablet y escritorio y entre idiomas, *entonces* muestra el mismo renderizador del sitio público con los datos del borrador.
  Manual: comparar el lienzo con la página publicada a ~375 px y ~1280 px y comprobar que son iguales.
- **CA-022-36:** *Dado* el lienzo, *cuando* el organizador hace clic (o activa con Enter) un bloque, *entonces* queda seleccionado con un contorno visible, su formulario aparece en el panel de ajustes y un cambio se refleja en el lienzo sin guardar.
  Manual: seleccionar cada tipo de bloque, cambiar un texto y comprobar que el lienzo cambia al instante, a ~375 px y ~1280 px.
- **CA-022-37:** *Dado* un bloque seleccionado, *cuando* se usan «subir», «bajar», «duplicar» y «quitar» o sus atajos de teclado, *entonces* el orden y la lista cambian, el foco se queda en el bloque movido, un lector de pantalla anuncia la posición y quitar pide confirmación.
- **CA-022-38:** *Dado* el editor, *cuando* el organizador añade bloques desde la biblioteca, los ajusta, pulsa «Actualizar» y abre la página pública, *entonces* ve ahí los cambios; con «Guardar borrador» los conserva al recargar el editor sin que el público los vea.
  Manual: crear un evento, abrir su landing, añadir un bloque de cada tipo, pulsar «Guardar borrador», recargar, «Actualizar» y comprobar la página pública.
- **CA-022-39:** *Dado* el editor y la landing publicada, *cuando* se usan solo con teclado y un lector de pantalla, *entonces* los controles tienen nombre, el foco es visible, los errores se asocian a su campo, la jerarquía de encabezados es correcta, el contraste cumple AA y no hay errores en la consola.
  Manual: recorrer con Tab el editor y la landing publicada a ~375 px y ~1280 px y revisar la consola.
- **CA-022-40:** *Dado* el catálogo de componentes, *cuando* corren las pruebas, *entonces* `LandingRenderer` y cada bloque están registrados, se importan por ruta directa y respetan los tokens y `lib/classes.ts`.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| XSS o inyección desde un bloque de texto, enlace o imagen | Lista blanca de bloques y etiquetas (`RichTextSanitizer`), enlaces solo `https`, imágenes validadas por contenido y sin SVG, claves y rutas generadas por el servidor (CA-022-9 a 14, 29) |
| Fuga de contenido no publicado | Imágenes solo si están en `published_blocks`; el editor es una ruta autenticada (CA-022-8, 32) |
| El lienzo y el sitio público divergen | Un único `LandingRenderer` para ambos (ADR-0010) |
| Una landing mal diseñada rompe la accesibilidad | Variantes sobre tokens, alternativas obligatorias y verificación manual (DA-15, CA-022-13, 39) |
| El editor React dentro de Filament y Livewire (estado, foco, doble origen de verdad) | Un solo dueño del estado (el editor React); Livewire solo guarda; spike en F4 antes del resto |
| Pisadas entre dos editores | Versión optimista y conflicto explícito (CA-022-15) |
| Esfuerzo mayor al previsto (editor tipo Elementor) | Sin arrastre con ratón ni edición de texto sobre la página; si F5 excede, se corta lo que no sea mover, duplicar y quitar |
| **Desplegar código que usa tablas nuevas antes de migrar la base de Railway** | La migración la aplica una persona antes de usar la landing; la página pública cae a la actual si no hay landing publicada, y el editor falla a la vista, sin esconder el error |
| La creación de la landing en `CreateEvent` rompe la creación de eventos | Va dentro de la misma transacción y tiene prueba de atomicidad (CA-022-1) |
| Choca con los planes 019 (duplicar evento), 020 y 024 (panel y tarjeta) | La copia se añade al implementar el 019 (DA-11); las entradas del editor se acuerdan con el 020 y el 024 antes de F4 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | ba8810b | 2026-11-19 | Pint sin cambios; Larastan sobre mis archivos: 0 errores; Pest `LandingBlockRulesTest`: 25 pruebas, 51 aserciones; `composer check:criteria`: 0 errores. El commit arrastró por error archivos de frontend que estaban en el índice compartido (el contenido es correcto, solo la agrupación). Desviación de forma: el bloque se guarda plano `{id, type, ...campos}` con los nombres del borrador del editor (`landing.ts`), no anidado en `content`; el esquema por tipo (`LandingBlockRules`) genera validación, saneado y presentación al editor. |
| F1 | 9c69056 | 2026-11-19 | Migraciones aplicadas solo en la base local; `SchemaTest` y `PolicyTest`, más guardianas de tenancy (`TenantIsolationTest`, trigger de `organization_id`, claves): verde. La clave foránea de `landing_images` hacia la landing es de tres columnas `(landing, evento, organización)` (más estricta que la propuesta). **Una persona debe aplicar `php artisan migrate --force` en Railway antes de usar la landing.** Hasta entonces la página pública del evento falla por tabla inexistente (el fallo se propaga a propósito). |
| F2 | e5a0faa | 2026-11-19 | `LandingActionsTest`: atomicidad al crear el evento y al actualizar, conflicto de versión, auditoría sin contenido, imágenes (contenido real, 5 MB, límite de 20, borrado al actualizar). Tipo `landing` añadido a `ProgramImageType`. Los ids que manda el cliente se ignoran salvo los ya presentes en el borrador (identidad estable entre guardados). |
| F3 | 2bcbac5 | 2026-11-19 | `PublicLandingPageTest`: CA-022-21 a 28 y 32. Conjunto `Landings` + `TenantIsolationTest`: 171 pruebas, 641 aserciones, verde. Las props llevan `landing: {blocks, meta}` o `null`. |
| F4 | 81d93c2 | 2026-11-19 | `LandingEditorPageTest`: 15 pruebas, 69 aserciones, verde (permisos, 404 entre organizaciones, creación perezosa, guardar, actualizar, descartar, conflicto, subida). Larastan sobre `app/` y `tests/Feature/Landings`: sin errores propios. **No ejecutado:** suite completa de Pest, `composer check`, verificación en navegador a 375 y 1280 px (CA-022-35, 36, 38 y 39 son manuales). La entrada «Diseñar landing» de la fila de convocatoria está en su menú «⋮». |
