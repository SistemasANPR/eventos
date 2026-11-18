# feat: Landing de la convocatoria con editor de bloques

> Plan 022. Especificación (SDD, ADR-0001). **Borrador: no se implementa nada hasta que se apruebe y se cierren las decisiones abiertas.**

- **Fecha:** 2026-11-18
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna directa. Se apoya en la excepción de la vista previa en React del ADR-0010 y en el disco `media` del plan 021 (DA-11).

## 1. Objetivo y alcance

Hoy la página pública de una convocatoria es una plantilla fija: título, descripción en texto plano, estado, fechas, ejes y el botón de envío. El organizador no puede cambiar su aspecto ni añadir contenido propio (imagen principal, texto con formato, preguntas frecuentes, galería). El plan maestro dejó la landing informativa fuera de R0, en el sitio actual (`docs/plan-de-desarrollo.md`, RC-5); este plan la trae a la plataforma.

Cuando el organizador **publica una convocatoria** se crea su **landing** con una plantilla predeterminada y queda visible en la URL de la convocatoria que ya existe. Desde la fila de la convocatoria (junto a «Editar» y «Publicar») y desde su pantalla de edición se abre un **editor visual de la landing**, inspirado en Elementor: a un lado la página tal como la verá el visitante, y al otro un panel para elegir, ajustar, ordenar, duplicar y quitar bloques, con vistas de móvil, tablet y escritorio. Los cambios se guardan en un borrador y se publican cuando el organizador quiere.

**Dentro:**
- Una landing por convocatoria, con borrador y versión publicada.
- Catálogo cerrado de bloques (lista blanca), con textos por idioma de la convocatoria.
- Editor visual de dos zonas (lienzo y panel de ajustes) en una pantalla propia del panel de organizadores, que muestra la página con el mismo renderizador React que usa el sitio público. Se elige un bloque haciendo clic en el lienzo.
- Entradas al editor: acción «Diseñar landing» en la fila de la convocatoria y botón en su pantalla de edición.
- Imágenes subidas por el organizador, en un disco privado y servidas por la aplicación.
- Al publicar la convocatoria se crea y se publica su landing con la plantilla; la página actual sigue funcionando para convocatorias sin landing publicada.
- Publicar la landing, descartar el borrador y registro en la auditoría.

**Fuera:**
- Arrastrar y soltar con el ratón y edición del texto directamente sobre la página: en este plan los bloques se mueven con botones y teclado (DA-2); el arrastre puede ser un plan posterior, con una librería que hoy no está autorizada.
- HTML, CSS o JavaScript personalizados, `iframe` y videos incrustados.
- Landing por evento (una página del evento con su propio editor) y sitio de varias páginas.
- Marca blanca por organización y colores libres: el aspecto sale de los tokens de `design.md` (ADR-0004, DA-4).
- Historial de versiones, deshacer y rehacer, edición simultánea de varias personas.
- Renderizado en servidor (SSR): sigue diferido (RC-5); la landing lleva metadatos básicos.
- Bloques que dependen de módulos que aún no existen (patrocinadores, mapa de la sede, boletos).
- Calendario: no entra en R0.

## 2. Punto de partida

Medido el 2026-11-18 sobre `staging`:

- **Página actual:** `ShowCallController` entrega `Calls/Show` (`resources/js/pages/Calls/Show.tsx`, 114 líneas) con `PublicCallData`: título, descripción en texto plano (traducibles), estado, fechas en la zona del evento, ejes activos y botón de envío solo con la convocatoria abierta. No hay bloques, imágenes ni formato. Un borrador, otra organización o un evento sin publicar responden 404 (CA-004-20).
- **Modelo:** `CallForSubmissions` (`BelongsToOrganization`, `title` y `description` traducibles, `locales`, `slug`, `form_definition_id`) y `CreateCallForSubmissions` en `app/Actions/Calls/`. No tiene campos de landing. Se gestionan en `CallsRelationManager`, bajo el evento.
- **Permisos:** `calls.manage` y `calls.view` ya existen en `RolePermissions`. No hay un permiso de landing.
- **Patrones reutilizables:** definición en `jsonb` validada con una clase de esquema y con límites de tamaño (ADR-0010); vista previa dentro del panel que monta el mismo componente React del sitio público (ADR-0010, excepción acotada al ADR-0004, planes 005 y 021); `RichTextSanitizer` con lista blanca para texto enriquecido (plan 021, DA-16); disco `media`, `StoreProgramImage` y rutas públicas de imágenes que solo sirven lo publicado (plan 021, DA-11); catálogo de componentes (`design.md` §1.4) con `RichText`, `EventHero` y `TextLink`.
- **Planes vecinos:** el 019 (duplicar evento, Borrador) copia las convocatorias; el 020 (panel con el evento como eje, Aprobado) fija dónde viven las pantallas del evento; el 021 (ponentes, Aprobado) deja el disco `media` y los ponentes publicados.
- **Pantalla de convocatorias:** `CallsRelationManager`, dentro de la vista del evento, lista cada convocatoria con las acciones «Editar» y «Publicar» en su fila (comprobado en el panel el 2026-11-18). Ahí se añade «Diseñar landing».
- **Nombres:** el glosario ya usa «Bloque» para `ScheduleBlock` (rango de horario del programa). El bloque de la landing debe nombrarse distinto (DA-13).
- **Seguridad:** `docs/seguridad.md` pide sanear en el servidor el texto con formato y no servir `.svg` con scripts ni `.html`; la CSP se ajusta a los scripts reales del sitio público.

## 3. Decisiones

Todas abiertas, con su recomendación. Se cierran antes de implementar y se anota la fecha.

- DA-1: la landing es **de la convocatoria** (una por convocatoria).
- DA-2: **editor visual de dos zonas** (lienzo con la página real y panel de ajustes), sin arrastre con ratón.
- DA-3: catálogo de **9 bloques** de lista blanca.
- DA-4: datos en `jsonb` validado, con **borrador y versión publicada**.
- DA-5: textos **por idioma de la convocatoria**.
- DA-6: la landing **se crea y se publica con plantilla al publicar la convocatoria**; sin landing publicada se ve la página actual.
- DA-7: imágenes en el disco `media`, servidas **solo si están en la versión publicada**.
- DA-8: texto enriquecido con **`RichTextSanitizer`** y enlaces `https`.
- DA-9: **sin SSR** (RC-5), con metadatos básicos.
- DA-10: se **reutiliza `calls.manage`**; sin permiso nuevo.
- DA-11: duplicar el evento copia la landing **en borrador**.
- DA-12: guardado explícito, **sin historial de versiones**.
- DA-13: nombres `CallLanding` y «bloque de landing».
- DA-14: **límites** de tamaño.
- DA-15: aspecto solo con **tokens** predefinidos.
- DA-16: **momento**: después de R0.

### DA-1 · Alcance de la landing — se recomienda **una landing por convocatoria**

Opciones: (a) por convocatoria; (b) por evento, con la convocatoria como un bloque; (c) ambas. El pedido es «al generar una convocatoria, aparece la landing», y la URL pública que ya existe es la de la convocatoria. Recomendación (a): no cambia rutas, las convocatorias de un mismo evento pueden tener mensajes distintos (por ejemplo, ponencias y pósteres) y cada una se publica por separado. La landing del evento queda como un plan futuro, que reutilizaría el mismo renderizador y el mismo esquema de bloques.

### DA-2 · Tipo de editor — se recomienda **editor visual de dos zonas, sin arrastre con ratón**

Se pidió un editor «tipo Elementor». Opciones: (a) **editor visual de dos zonas**: pantalla propia en el panel con el **lienzo** a un lado (la página real, montada con el mismo `LandingRenderer` que el sitio público) y un **panel de ajustes** al otro; se hace clic en un bloque del lienzo para seleccionarlo y su formulario aparece en el panel; una biblioteca de bloques para añadir; botones y teclado para mover, duplicar y quitar; interruptor de móvil, tablet y escritorio; (b) lienzo con arrastrar y soltar y edición directa del texto sobre la página; (c) formulario de bloques de Filament con una vista previa aparte.

Se recomienda (a): da lo esencial de la experiencia de Elementor (ver la página, elegir un bloque con un clic, ajustarlo y ver el cambio al instante) sin una librería de arrastre, que hoy no está en la lista de dependencias autorizadas y tiene un riesgo alto de accesibilidad con teclado. Mover con botones («subir» y «bajar») y teclado cumple AA. Se apoya en el patrón ya aceptado del ADR-0010 (React montado dentro de una página del panel, con el mismo componente del sitio público para que no diverjan); aquí se amplía de «vista previa de solo lectura» a «lienzo que selecciona bloques». El estado del editor se guarda con acciones de Livewire llamadas desde la página (`$wire`), así que **no se crea ninguna ruta JSON nueva**.

Alternativas descartadas: (b) exige añadir la librería de arrastre (requiere permiso, acción 4 de `AGENTS.md`), mucho más esfuerzo y edición de texto en línea con alto riesgo de XSS y accesibilidad; puede ser el plan siguiente sobre el mismo esquema de datos y el mismo lienzo. (c) es la opción barata, pero no se siente como un editor visual y duplica la vista previa.

### DA-3 · Catálogo de bloques — se recomienda **9 bloques de lista blanca**

`hero` (título, subtítulo, imagen de fondo y botón), `rich_text` (texto con formato), `key_dates` (fechas de apertura, cierre y resultados, tomadas de la convocatoria: no se escriben a mano), `tracks` (ejes activos, tomados de la convocatoria), `call_to_action` (botón «Enviar propuesta», que solo aparece con la convocatoria abierta, o un enlace `https`), `faq` (preguntas y respuestas), `image_gallery` (hasta 12 imágenes con texto alternativo obligatorio), `spacer` (separación y divisor) y `columns` (dos columnas de texto o imagen). Los bloques con datos del sistema (`key_dates`, `tracks`) nunca guardan una copia: se leen al mostrar, para no mostrar fechas vencidas. Fuera de R1: ponentes destacados (depende de los campos públicos del plan 021), patrocinadores, mapa, video y HTML libre. Cada bloque tiene un identificador generado por el servidor, un tipo de la lista blanca y un contenido validado por su propia clase.

### DA-4 · Almacenamiento — se recomienda **`jsonb` validado, con borrador y versión publicada**

Una tabla `call_landings` (una fila por convocatoria) con `draft_blocks` y `published_blocks` en `jsonb`, `version` y `published_at`. La forma de cada bloque se valida en la aplicación con una clase de esquema con pruebas, porque PostgreSQL no valida el interior del `jsonb` (ADR-0010); la base solo comprueba que sea un arreglo y su tamaño. Alternativa descartada: una tabla de bloques con una fila cada uno (EAV): multiplica consultas y transacciones para una lectura que siempre es «toda la página»; y una columna en `call_for_submissions`: mezcla la convocatoria con contenido de presentación y hace crecer una tabla caliente.

### DA-5 · Idiomas — se recomienda **textos por idioma de la convocatoria**

Cada texto de un bloque es un objeto `{es, en, pt}` con los idiomas de `call.locales`; el editor muestra una pestaña por idioma y marca los textos que faltan. Si falta un idioma, se muestra el idioma base del evento (igual que hoy, CA-004-19). Las imágenes tienen el texto alternativo por idioma. Los textos de la plataforma (etiquetas fijas como «Fechas clave») salen de `lang/`.

### DA-6 · Nacimiento y compatibilidad — se recomienda **la landing se crea y se publica al publicar la convocatoria; la página actual sigue como respaldo**

Regla: **publicar la convocatoria publica su landing.** `PublishCall` crea la landing con una plantilla predeterminada (`hero`, `key_dates`, `tracks`, `call_to_action`) si todavía no existe, y publica su borrador actual. Así la landing aparece sola desde el primer día, tal como se pidió. El organizador puede abrir el editor **antes** de publicar la convocatoria (la landing se crea entonces, en borrador, al abrirlo) y preparar lo que quiera; al publicar la convocatoria se publica ese borrador. Después de eso, cada cambio exige «Publicar landing» (DA-12). Las convocatorias ya publicadas antes de este plan no se tocan: su landing se crea, en borrador, la primera vez que se abre el editor, y hasta que la publiquen sigue la página actual. Mientras no haya versión publicada, la ruta pública entrega la página actual sin cambios (compatibilidad hacia atrás, CA-004-17 a 21 intactos). Publicar la landing no cambia el estado de la convocatoria: la visibilidad (404 en borrador) la sigue decidiendo `status`.

Pregunta abierta para el negocio: si el organizador pausa o cierra la convocatoria, la landing sigue publicada (muestra el estado y oculta el botón de envío, CA-022-25). Se recomienda no despublicarla.

### DA-7 · Imágenes — se recomienda **disco `media`, servidas solo si están en la versión publicada**

Reutiliza `MediaDisk` y el criterio de `StoreProgramImage` del plan 021: JPEG, PNG y WebP validados por contenido, tope de 5 MB y 40 millones de píxeles, nombre generado, sin SVG, ruta bajo `organizations/{id}/events/{id}/media/landing/`. Se registran en `landing_images` y los bloques guardan solo el identificador. Una ruta pública sirve una imagen únicamente si la landing está publicada y la imagen figura en `published_blocks`: una imagen que solo está en el borrador no se filtra. La vista previa del panel la sirve otra ruta autenticada.

### DA-8 · Contenido y enlaces — se recomienda **`RichTextSanitizer` y solo `https`**

Todo texto con formato pasa por `RichTextSanitizer::clean()` al guardar (lista blanca existente: sin imágenes embebidas, `script`, `style` ni `javascript:`). Los botones y enlaces aceptan `https` externos o el destino fijo «formulario de envío»; nunca `javascript:` ni `data:`. Los enlaces externos llevan `rel="noopener noreferrer nofollow"`. El renderizador React no usa `dangerouslySetInnerHTML` salvo con HTML ya saneado por el servidor.

### DA-9 · SSR — se recomienda **mantener la decisión de diferirlo (RC-5)**

La landing se renderiza con Inertia como la página actual, con `<title>`, descripción y `og:image` desde el bloque `hero`. Si el posicionamiento en buscadores se vuelve prioritario, se reevalúa el SSR (ADR-0004, DA-1) en su propio plan.

### DA-10 · Permisos — se recomienda **reutilizar `calls.manage` y `calls.view`**

Editar, guardar, publicar y descartar exigen `calls.manage`; ver el borrador y la vista previa exigen `calls.view`. No se crea un permiso nuevo, así que no cambia la matriz de roles (acción 9 de `AGENTS.md`) y no hace falta una migración de permisos.

### DA-11 · Duplicar el evento — se recomienda **copiar la landing en borrador**

Cuando el plan 019 copie una convocatoria, copia también su landing como borrador, sin versión publicada, y duplica las imágenes con identificadores y rutas nuevas (nunca comparte archivos entre eventos). Hasta que el 019 se implemente, este plan no depende de él; la copia se añade en la fase del 019 con su prueba.

### DA-12 · Guardado e historial — se recomienda **guardado explícito, sin historial en R1**

El organizador pulsa «Guardar borrador», «Publicar» o «Descartar cambios» (vuelve a la versión publicada). Un guardado de un formulario con una `version` anterior se rechaza con conflicto, para que dos personas no se pisen. No hay deshacer ni historial de versiones en este plan; la auditoría registra quién publicó y cuándo.

### DA-13 · Nombres — se recomienda **`CallLanding` y «bloque de landing»**

El glosario ya usa «Bloque» para `ScheduleBlock`. Modelo `CallLanding` (tabla `call_landings`), `LandingBlock` (enum de tipos `LandingBlockType`) y, en textos visibles, «bloque de la landing». Se añade al glosario antes de implementar (ADR-0006: identificadores en inglés, textos en español).

### DA-14 · Límites — se recomienda **30 bloques, 128 KB, 20 imágenes**

Máximo 30 bloques por landing, 128 KB de `draft_blocks`, 20 imágenes por landing, 12 por galería, 20 preguntas en un `faq` y 5 000 caracteres por texto enriquecido. Los límites se validan en el servidor y se ajustan con el uso real.

### DA-15 · Aspecto — se recomienda **variantes predefinidas sobre tokens**

El organizador elige entre variantes (fondo claro o acento, alineación, ancho) y no escribe colores ni CSS. Todo sale de los tokens y clases de `design.md` y `lib/classes.ts`, así que el contraste AA se cumple por construcción y el catálogo de componentes lo vigila.

### DA-16 · Momento — se recomienda **después de R0**

El plan maestro deja la landing en el sitio actual para R0. Aprobar el plan no obliga a implementarlo antes del lanzamiento: se programa para la fase posterior, cuando el CI esté en verde y las verificaciones de los planes 005, 006 y 009 estén cerradas.

## 4. Contrato

**Rutas del panel** (Filament, bajo el evento; no son JSON): la pantalla del editor de la landing de cada convocatoria (lienzo y panel de ajustes, a pantalla completa dentro del panel), y su subida de imágenes. Se llega desde la acción de fila «Diseñar landing» de `CallsRelationManager` (junto a «Editar» y «Publicar») y desde un botón en la pantalla de edición de la convocatoria. Guardar, publicar, descartar y subir imágenes son acciones de Livewire llamadas desde el editor React con `$wire` (POST con CSRF); no hay rutas JSON nuevas.

**Rutas públicas nuevas:**
- `GET /{organization}/events/{event}/call/{call}/landing/images/{image}` (`public.landings.image`): sirve una imagen de la versión publicada; 404 si no hay landing publicada, si la imagen no está en `published_blocks`, si es de otra convocatoria u organización o si el evento no está publicado. Cabeceras `X-Content-Type-Options: nosniff` y caché pública corta. No es JSON; se documenta en `docs/api/endpoints.md` junto con las de imágenes del programa.
- `calls.show` no cambia de URL: con landing publicada entrega, además de las props actuales, `blocks` ya resueltos.

**Props** (`resources/js/types/landing.ts`, a mano): unión discriminada `LandingBlock` por `type`, con `PublicLandingBlock` resuelto en el servidor (fechas y ejes calculados, URLs de imágenes ya construidas, textos ya en el idioma pedido). La página no recibe identificadores internos de organización ni el borrador (CA-004-21 se extiende).

**Validación:** clase de esquema `LandingBlockRules` (un conjunto de reglas por tipo) usada por las Actions `SaveCallLanding` y `PublishCallLanding`, con sus pruebas; Form Request propio para la subida de imágenes.

**Acciones nuevas** (`app/Actions/Calls/`): `SaveCallLanding`, `PublishCallLanding`, `DiscardCallLandingDraft`, `UploadLandingImage`, `DeleteLandingImage`, `ResolveLandingBlocks` (arma lo público desde `published_blocks`).

**Componentes:** `LandingRenderer` (organismo) y un componente por tipo de bloque, todos registrados en `catalog/registry.ts` y con demo en `/components` (`design.md` §1.4). El editor (`LandingEditor`, con `EditorCanvas`, `BlockLibrary` y `BlockSettingsPanel`) monta `LandingRenderer` como lienzo, con el mismo código del sitio público, y envuelve cada bloque para poder seleccionarlo.

### 4.1 Propuesta de datos (`docs/database-design.md` §6)

1. **Necesidad:** hecho de negocio nuevo: el contenido de presentación de una convocatoria. Dueño: módulo de convocatorias (plan 004). Ciclo de vida: nace con la convocatoria, se edita en borrador, se publica, se archiva y se elimina con ella.
2. **Inspección:** `call_for_submissions` (sin campos de landing), `form_definitions` (patrón `jsonb` con versión), tablas de archivos del plan 007 y 021 (patrón de rutas y claves compuestas), `activity_log`.
3. **Modelo propuesto:**
   - `call_landings`: `id uuid`, `organization_id uuid NOT NULL`, `event_id uuid NOT NULL`, `call_id uuid NOT NULL UNIQUE`, `draft_blocks jsonb NOT NULL`, `published_blocks jsonb NULL`, `version integer NOT NULL DEFAULT 1`, `published_at timestamptz NULL`, `published_by uuid NULL`, `created_at`, `updated_at`, `deleted_at`. Ámbito: evento (con su organización).
   - `landing_images`: `id uuid`, `organization_id`, `event_id`, `call_landing_id`, `path`, `mime`, `size_bytes`, `width`, `height`, `created_at`, `deleted_at`. Ámbito: evento.
4. **Integridad:** claves foráneas compuestas `(call_id, organization_id)`, `(call_id, event_id)` y `(call_landing_id, organization_id)`; `CHECK` de que ambos `jsonb` son arreglos y de su tamaño; `CHECK` de que `published_at` y `published_blocks` van juntos; índice único en `call_id`; `SoftDeletes` en ambas; el modelo usa `BelongsToOrganization`.
5. **Cambio:** dos migraciones nuevas, sin tocar las existentes y sin datos que migrar (las convocatorias actuales no tienen fila: se crea al abrir el editor). La ruta actual sigue funcionando durante el despliegue. Verificación: prueba de inserción cruzada, aislamiento entre organizaciones y reversa de la migración. La aplica un humano en una base real.
6. **Alternativa descartada:** ver DA-4 (EAV y columna en la convocatoria).
7. **Preguntas de negocio:** ninguna que impida el esquema.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Ver el borrador y la vista previa: `calls.view`. Guardar, publicar, descartar y subir imágenes: `calls.manage`. El visitante solo ve la versión publicada. Sin cambios en la matriz de roles (DA-10) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | Se amplía la Policy de la convocatoria con `viewLanding`, `updateLanding` y `publishLanding`, y una prueba por rol (permitido y denegado). Cada Action autoriza con `authorizeActor` |
| ¿Modelos nuevos con datos de una organización? | Sí: `CallLanding` y `LandingImage`, con `BelongsToOrganization` y entrada en `tests/Feature/Tenancy/` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Sí, como el de las convocatorias: el acceso a un evento concreto lo decide la Policy. Un identificador de otra organización o evento responde 404 |
| ¿Se guardan datos personales nuevos? | No los pide la plataforma. Un organizador podría escribir nombres o fotografías de personas en un bloque: es contenido público que él decide publicar. Se avisa en el editor y se documenta en `docs/privacidad.md`; la landing no lee datos de ponentes en este plan |
| ¿Se suben archivos? | Sí: JPEG, PNG y WebP, hasta 5 MB y 40 millones de píxeles, disco `media` privado, nombre generado, validación por contenido, sin SVG (DA-7) |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | `landing_published`, `landing_draft_discarded` e `landing_image_uploaded` o `deleted`, con identificadores y sin contenido |

## 6. Estrategia por fases

Cada fase deja el repositorio en verde y termina en su propio commit. La fase F1 necesita la aprobación de la propuesta de datos de §4.1.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Esquema de bloques: enum `LandingBlockType`, clases de reglas por tipo, tipos TypeScript, plantilla predeterminada, términos del glosario y entrada en `docs/api/endpoints.md`. Sin comportamiento visible | S |
| F1 | Datos: migraciones, modelos, factories, Policy y pruebas de aislamiento | M |
| F2 | Acciones: crear y publicar la landing con plantilla al publicar la convocatoria (`PublishCall`), crear al abrir el editor, guardar, publicar, descartar y subir imágenes, con auditoría | M |
| F3 | Sitio público: `LandingRenderer` y los 9 bloques en el catálogo, `ShowCallController` con respaldo a la página actual y la ruta de imágenes | M |
| F4 | Editor visual, primera parte: pantalla del editor con lienzo y panel de ajustes, entradas desde la fila y desde la edición de la convocatoria, selección de bloque con clic, formulario por bloque y pestañas de idioma | L |
| F5 | Editor visual, segunda parte: biblioteca para añadir bloques, mover, duplicar y quitar con botones y teclado, interruptor móvil, tablet y escritorio, guardar, publicar y descartar | L |
| F6 | Accesibilidad AA y verificación en navegador a ~375 y ~1280 px; copia al duplicar el evento (cuando exista el plan 019) | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-022-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Datos y aislamiento**

- **CA-022-1:** *Dado* una convocatoria en borrador sin landing, *cuando* el organizador la publica, *entonces* se crea su landing con los bloques de la plantilla predeterminada, se publica en la misma operación y la página pública de la convocatoria la muestra; si falla la creación, la convocatoria no queda publicada a medias (transacción).
- **CA-022-2:** *Dado* una convocatoria sin landing (en borrador, o publicada antes de este plan), *cuando* el organizador abre el editor por primera vez, *entonces* se crea su landing en borrador con la plantilla y la página pública no cambia hasta que la publique.
- **CA-022-38:** *Dado* una convocatoria en borrador cuya landing el organizador ya editó, *cuando* publica la convocatoria, *entonces* se publica ese borrador editado y no se reemplaza por la plantilla.
- **CA-022-3:** *Dado* la base de datos, *cuando* se inserta una landing o una imagen con el `organization_id` de A y la convocatoria o el evento de B, *entonces* la clave foránea compuesta lo rechaza (prueba de inserción cruzada).
- **CA-022-4:** *Dado* un administrador de A, *cuando* abre, guarda, publica o pide la imagen de la landing de una convocatoria de B, *entonces* responde 404 y no cambia nada.
- **CA-022-5:** *Dado* `CallLanding` y `LandingImage`, *cuando* se ejecutan las pruebas guardianas de tenancy, *entonces* ambos están declarados con `BelongsToOrganization` y sin contexto de organización la consulta lanza `MissingOrganizationContext`.

**Permisos**

- **CA-022-6:** *Dado* un usuario con `calls.manage`, *cuando* guarda, publica o descarta, *entonces* la operación se realiza; con `calls.view` solo puede ver el borrador y la vista previa y recibe 403 al modificar; sin ninguno, 403 también al ver.
- **CA-022-7:** *Dado* un rol sin `calls.manage` que llama a la Action o a la ruta de subida directamente, *cuando* lo intenta, *entonces* recibe 403 y no se guarda ni se sube nada (la interfaz oculta el botón, pero el servidor decide).
- **CA-022-8:** *Dado* un visitante o un autor con sesión, *cuando* pide el borrador, la vista previa o una imagen no publicada, *entonces* recibe 404 o 403 sin revelar el contenido.

**Bloques y validación**

- **CA-022-9:** *Dado* un bloque con un tipo fuera de la lista blanca, o con campos que su tipo no admite, *cuando* se guarda, *entonces* se rechaza con error de validación por campo y no se guarda ninguno.
- **CA-022-10:** *Dado* una landing con más de 30 bloques, más de 128 KB, más de 20 imágenes, una galería de más de 12 o un `faq` de más de 20 preguntas, *cuando* se guarda, *entonces* se rechaza indicando el límite y no cambia el borrador.
- **CA-022-11:** *Dado* un texto enriquecido con `<script>`, `<iframe>`, `<svg>`, atributos `on*`, `javascript:` o imágenes embebidas, *cuando* se guarda, *entonces* queda saneado con la lista blanca y no aparece en la página pública.
- **CA-022-12:** *Dado* un botón o enlace con destino `javascript:`, `data:` o `http`, *cuando* se guarda, *entonces* se rechaza; un `https` externo se acepta y se renderiza con `rel="noopener noreferrer nofollow"`.
- **CA-022-13:** *Dado* un bloque de imagen sin texto alternativo en el idioma base, *cuando* se guarda, *entonces* se rechaza (accesibilidad).
- **CA-022-14:** *Dado* los identificadores y claves de los bloques, *cuando* llegan del cliente, *entonces* el servidor los genera y los fija; un identificador enviado por el cliente se ignora.
- **CA-022-15:** *Dado* dos guardados con la misma `version` de partida, *cuando* el segundo llega después del primero, *entonces* se rechaza con conflicto y no pisa el primero.

**Publicación**

- **CA-022-16:** *Dado* un borrador válido, *cuando* el organizador publica, *entonces* `published_blocks` es una copia del borrador, la `version` aumenta, quedan `published_at` y quién publicó, y se registra `landing_published` sin contenido.
- **CA-022-17:** *Dado* cambios en el borrador sin publicar, *cuando* un visitante pide la página, *entonces* ve la última versión publicada y no los cambios.
- **CA-022-18:** *Dado* un borrador con cambios, *cuando* el organizador descarta, *entonces* el borrador vuelve a ser igual a la versión publicada (o a la plantilla si nunca se publicó) y se registra `landing_draft_discarded`.
- **CA-022-19:** *Dado* una publicación repetida sin cambios, *cuando* se vuelve a ejecutar, *entonces* no duplica auditoría ni aumenta la `version` (idempotente).
- **CA-022-20:** *Dado* un fallo a mitad de publicar, *cuando* ocurre, *entonces* la transacción se revierte y la versión publicada anterior queda intacta.

**Sitio público**

- **CA-022-21:** *Dado* una convocatoria publicada con landing publicada, *cuando* un visitante pide su URL, *entonces* ve los bloques en el orden guardado, en el idioma pedido (o el base si falta) y con las fechas en la zona del evento.
- **CA-022-22:** *Dado* una convocatoria sin landing publicada, *cuando* un visitante pide su URL, *entonces* ve la página actual idéntica a la de hoy (CA-004-17 a 21 siguen pasando).
- **CA-022-23:** *Dado* una convocatoria en borrador, de otra organización, eliminada o con evento sin publicar, *cuando* se pide su URL, *entonces* responde 404 igual que hoy, tenga o no landing publicada.
- **CA-022-24:** *Dado* el bloque `key_dates` o `tracks`, *cuando* cambian las fechas o los ejes de la convocatoria, *entonces* la página refleja el cambio sin republicar la landing (no guarda copia).
- **CA-022-25:** *Dado* el bloque `call_to_action` de envío, *cuando* la convocatoria no está abierta (programada, pausada o cerrada), *entonces* no muestra el botón y muestra el estado; con la convocatoria abierta enlaza al formulario.
- **CA-022-26:** *Dado* las props de la página, *cuando* se renderiza, *entonces* no incluyen el borrador, identificadores internos de organización ni datos del equipo (extiende CA-004-21).
- **CA-022-27:** *Dado* la landing publicada, *cuando* se renderiza, *entonces* el `<title>`, la descripción y `og:image` salen del bloque `hero` y de la convocatoria.

**Imágenes**

- **CA-022-28:** *Dado* un archivo cuyo contenido no es JPEG, PNG ni WebP (SVG, HTML, ejecutable o una imagen con código incrustado), *cuando* se sube, *entonces* se rechaza por el contenido real aunque el nombre o el tipo declarado sean válidos, y no queda nada en el disco.
- **CA-022-29:** *Dado* una imagen de más de 5 MB o de más de 40 millones de píxeles, *cuando* se sube, *entonces* se rechaza.
- **CA-022-30:** *Dado* una imagen subida, *cuando* se guarda, *entonces* el nombre en disco lo genera el servidor, vive bajo la ruta de su organización y evento, y los bloques guardan su identificador, no su ruta.
- **CA-022-31:** *Dado* una imagen que solo está en el borrador, *cuando* un visitante pide su URL, *entonces* responde 404; una imagen presente en `published_blocks` se sirve con `nosniff`.
- **CA-022-32:** *Dado* una imagen quitada de la landing, *cuando* se publica, *entonces* deja de servirse al público y se marca como eliminada.

**Editor y vista previa**

- **CA-022-33:** *Dado* el editor, *cuando* el organizador añade, ordena, duplica, quita y edita bloques y cambia de idioma, *entonces* el borrador refleja esos cambios al guardar y los textos faltantes de un idioma quedan marcados.
  Manual: crear una convocatoria, abrir su landing, añadir un bloque de cada tipo, reordenarlos, duplicar uno, quitar otro y guardar; recargar y comprobar que el borrador se conservó.
- **CA-022-34:** *Dado* el lienzo del editor, *cuando* se alterna entre móvil, tablet y escritorio y entre idiomas, *entonces* muestra el mismo renderizador del sitio público con los datos del borrador, y no es una ruta pública.
  Manual: comparar el lienzo con la página publicada a ~375 px y ~1280 px y comprobar que son iguales.
- **CA-022-39:** *Dado* la lista de convocatorias de un evento, *cuando* un usuario con `calls.manage` ve una convocatoria, *entonces* la fila ofrece «Diseñar landing» y la pantalla de edición ofrece abrir el editor; ambas llevan a la misma pantalla. Sin `calls.manage` no se ofrece ninguna y la ruta directa responde 403.
- **CA-022-40:** *Dado* el lienzo, *cuando* el organizador hace clic (o activa con Enter) un bloque, *entonces* queda seleccionado, se marca con un contorno visible y su formulario aparece en el panel de ajustes; un cambio en el formulario se refleja en el lienzo sin guardar.
  Manual: seleccionar cada tipo de bloque, cambiar un texto y comprobar que el lienzo cambia al instante, a ~375 px y ~1280 px.
- **CA-022-41:** *Dado* un bloque seleccionado, *cuando* se usan los botones «subir», «bajar», «duplicar» y «quitar» o sus atajos de teclado, *entonces* el orden y la lista cambian, el foco se queda en el bloque movido y un lector de pantalla anuncia la nueva posición; quitar pide confirmación.
- **CA-022-35:** *Dado* el editor y la vista previa, *cuando* se usan solo con teclado y un lector de pantalla, *entonces* los controles tienen nombre, el foco es visible y los errores se asocian a su campo.
  Manual: recorrer con Tab el editor y la landing publicada a ~375 px y ~1280 px y revisar la consola.

**Accesibilidad y seguridad del sitio**

- **CA-022-36:** *Dado* la landing publicada, *cuando* se revisa, *entonces* tiene una jerarquía de encabezados correcta, contraste AA (solo tokens), imágenes con alternativa y navegación completa con teclado, sin errores en la consola.
  Manual: abrir la landing a ~375 px y ~1280 px, recorrerla con Tab y revisar la consola.
- **CA-022-37:** *Dado* el catálogo de componentes, *cuando* se ejecutan las pruebas, *entonces* `LandingRenderer` y cada bloque están registrados, se importan por ruta directa y respetan los tokens y `lib/classes.ts`.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| XSS o inyección de contenido desde un bloque de texto, enlace o imagen | Lista blanca de bloques y de etiquetas (`RichTextSanitizer`), enlaces solo `https`, imágenes validadas por contenido y sin SVG, el servidor genera claves y rutas, CSP ajustada (CA-022-9 a 14, 28) |
| Fuga de contenido no publicado (borrador o imágenes) | Las imágenes solo se sirven si están en `published_blocks`; la vista previa es una ruta autenticada (CA-022-8, 31) |
| La vista previa y el sitio público divergen | Un único `LandingRenderer` para ambos (ADR-0010, excepción acotada) |
| Una landing mal diseñada rompe la accesibilidad | El organizador elige variantes sobre tokens, no CSS libre; alternativas obligatorias; pruebas y verificación manual (DA-15, CA-022-13, 36) |
| Pisadas entre dos editores | Versión optimista y conflicto explícito (CA-022-15); sin edición simultánea en este plan |
| Esfuerzo mayor al previsto (editor visual tipo Elementor) | El editor se parte desde ya en dos fases (F4 y F5); se descartan el arrastre con ratón y la edición de texto sobre la página (DA-2); si F5 excede, se corta lo que no sea mover, duplicar y quitar |
| El editor React dentro de Filament y Livewire (estado, foco, doble origen de verdad) | Un solo dueño del estado (el editor React); Livewire solo guarda, publica y sube imágenes; spike en F4 antes de construir el resto |
| Publicar la convocatoria publica la landing sin que el organizador la revise | La plantilla es neutra y solo usa datos de la convocatoria; la landing se puede editar o despublicar al instante; se muestra un aviso al publicar |
| Rutas y datos de la landing chocan con el plan 020 (panel con el evento como eje) | La página «Landing» vive en la sub-navegación del evento y se acuerda con el 020 antes de F4 |
| Choca con el plan 019 (duplicar evento) | La copia se añade al implementar el 019 (DA-11); este plan no lo bloquea |
| Programarlo antes de R0 compite con cerrar los planes 005, 006 y 009 | DA-16: se programa después de R0 |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
