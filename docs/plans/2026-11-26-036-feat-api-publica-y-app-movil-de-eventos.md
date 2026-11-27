# feat: API pública v1 y app móvil multi-evento (Expo)

> Plan 036. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 036: todos sus criterios son `CA-036-n`. Cubre **dos entregables**: (1) la API pública de solo lectura en este repositorio y (2) la app móvil «Eventos ANPR» en un repositorio aparte. El segundo no se construye hasta cerrar el contrato del primero.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-26
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna abierta. Este plan propone puntos nuevos (§8).

## 1. Objetivo y alcance

Hoy lo público de un evento (programa, ponentes, hospedaje, patrocinadores) solo se ve en el sitio web. Se quiere una **app contenedora multi-evento**: una sola app en App Store y Play Store; el asistente la descarga, **elige su evento** y la app se despliega con los datos de ese evento (programa, ponentes, hospedaje, patrocinadores, sede). La app **consume una API** del sistema web; no tiene acceso a la base de datos ni a datos internos.

**Dentro:**
- API pública `/api/v1/public/*`, **solo lectura**, sin autenticación, con límite de peticiones por IP, que expone únicamente lo que el sitio público ya muestra.
- Búsqueda y selección de evento: por nombre, por código corto y por QR / enlace.
- Contenido por evento: datos del evento, programa por día, salas, ponentes, hospedaje, patrocinadores.
- Imágenes públicas servidas con caché y variantes de tamaño.
- Idioma por `Accept-Language` (es, en), igual que el sitio público.
- Caché condicional (`ETag`) para que la app descargue solo lo que cambió.
- App móvil en Expo (React Native + TypeScript): selector de evento, pestañas de contenido, «mi agenda» local, modo sin conexión de solo lectura, enlaces profundos, publicación en las tiendas.
- Contrato OpenAPI como punto de unión entre ambos repositorios.

**Fuera (planes posteriores):**
- Cuentas de asistentes, inicio de sesión y boletos.
- Notificaciones push (requieren cuentas o registro de dispositivo y un ADR: Expo Push o FCM).
- Check-in con QR, registro a actividades, networking, mensajes y encuestas.
- Escrituras de cualquier tipo desde la app.
- Una app por evento (white-label).
- Pagos o datos personales de asistentes.

## 2. Punto de partida

Medido el 2026-11-26 sobre `staging` (`bdf596c`):

- **No existe ninguna ruta de API.** `routes/` solo tiene `web.php`, `author.php` y `console.php`; `docs/api/endpoints.md` tiene la tabla del catálogo vacía (`[RELLENAR]`). Sí define las reglas: prefijo `/api/v1/`, sobre `{success, data, meta}`, paginación `page`/`perPage` (máximo 100), códigos de error en `UPPER_SNAKE` y `/api/v1/public/*` sin autenticación y con límite estricto por IP.
- **Laravel Sanctum no está instalado.** El MVP no lo necesita (solo lectura pública); se decide en un plan posterior (DA-5).
- **Datos públicos ya resueltos en el sitio web:** programa y ponentes (plan 029: visibilidad = publicado, no oculto por reversa, no borrado y programado), salas (plan 031), hospedaje con varias imágenes y patrocinadores con categorías (plan 030), logotipo (027), formato de hora y zona horaria del evento (023), idiomas del evento (026), evento no disponible → 410 (032).
- **Aislamiento (ADR-0003):** `BelongsToOrganization` hace fallar la consulta sin contexto de organización. El sitio público fija el contexto con `SetOrganizationFromRoute` a partir de `{organization:slug}`. Una API de «todos los eventos» **no tiene** organización en la ruta: es la primera superficie que cruza organizaciones y exige un diseño explícito (DA-2).
- **Imágenes:** viven en el disco privado `media` y se sirven por rutas web con uuid y `Cache-Control: public, max-age=300`.
- **Constructores de datos públicos:** el plan 029 estableció que todo lo que viaja al cliente sale de un constructor explícito (`PublicEventData` y afines). La API debe reutilizar esos constructores, no duplicar la lógica de visibilidad.
- **Frontend:** React + TypeScript estricto en `resources/js`. La app móvil reutiliza el idioma y los tipos, no el código de componentes (Inertia y shadcn/ui no son portables).

## 3. Decisiones

Resumen: framework **Expo**; API **propia por zona pública** con selección por evento; sin autenticación en el MVP; repositorio de la app **aparte**; datos sin conexión con caché de lectura.

### DA-1 · Tecnología de la app — se recomienda **Expo (React Native + TypeScript)**

Opciones: PWA, Expo, Flutter, nativo, una app por evento. Expo gana por: el equipo ya usa React y TypeScript; un código genera iOS y Android; trae enlaces profundos, escáner de QR, notificaciones y almacenamiento local; EAS Build/Submit simplifica la publicación; EAS Update entrega correcciones de JavaScript sin revisión de tienda. La PWA queda como alternativa si se quisiera validar sin pasar por las tiendas, pero no cumple «descargar la app ANPR». Nativo y white-label se descartan por costo.

### DA-2 · Forma de la API y aislamiento — se recomienda **recurso `events` global y público, con contenido anidado por evento**

Opciones: (a) rutas `/api/v1/public/events/{event}/...` con `{event}` = uuid del evento y resolución del contexto de organización **a partir del evento**; (b) rutas `/api/v1/orgs/{organization}/...` como dice el catálogo para recursos de una organización. La app necesita descubrir eventos **sin conocer** la organización, así que el listado y la búsqueda son globales; el contenido va bajo el evento. Para no romper ADR-0003, **una sola clase** (`ResolvePublicEvent`) hace la única consulta global —únicamente eventos publicados, con `withoutOrganizationScope` documentado— y fija el contexto de organización antes de cualquier otra lectura. Cualquier otra consulta corre ya con organización fijada. Esto requiere **registrar el caso en el ADR-0003** (acción 10 de AGENTS.md); la delegación a Gabriel (2026-11-12) lo permite y debe quedar anotada aquí al cerrar.

### DA-3 · Identificador del evento en la API — se recomienda **uuid, más `code` corto para búsqueda**

El uuid no es adivinable y ya es la convención de las rutas de imagen. Para que el usuario encuentre su evento sin QR, se añade un **código corto** compartible (p. ej. `ANPR26`). Opciones: (a) usar el `slug` (único solo dentro de la organización: ambiguo); (b) columna nueva `public_code` única global. Recomendación: (b), con la propuesta de `docs/database-design.md` §6 en la fase F0. **Acción 1**: genera la migración el agente; solo el humano la aplica a una base real.

### DA-4 · Qué eventos aparecen en el selector — se recomienda **publicados y marcados «listado público»**

Un evento puede estar publicado pero ser privado (solo por enlace). Opciones: (a) listar todos los publicados; (b) listar solo los que el organizador marque como visibles en la app; los demás se abren únicamente por código, QR o enlace. Recomendación: (b), con la bandera `listed_in_app` en la misma propuesta de datos. Un evento archivado o no publicado responde 404 (o 410 si el plan 032 lo define así para archivado, para igualar el sitio web).

### DA-5 · Autenticación — se recomienda **ninguna en el MVP; Sanctum en el plan siguiente**

Todo lo del MVP ya es público. Introducir tokens ahora añade superficie sin valor. Cuando lleguen cuentas de asistentes, boletos o push, se decide con un ADR (Sanctum con tokens de dispositivo, ámbito de evento).

### DA-6 · Versionado y compatibilidad — se recomienda **`/api/v1` estable + cabecera de versión mínima**

La app instalada no se actualiza a la vez que el servidor. Reglas: solo se añaden campos (nunca se renombran ni quitan dentro de v1); un cambio incompatible crea `/api/v2` y v1 se mantiene hasta anunciar su retiro. La app envía `X-App-Version`; el servidor puede responder `426 APP_UPDATE_REQUIRED` en un caso extremo (se documenta pero no se usa en el MVP).

### DA-7 · Caché y modo sin conexión — se recomienda **`ETag` en el servidor y caché persistente en la app**

El servidor devuelve `ETag` y `Cache-Control: public, max-age=60` por recurso; responde 304 con `If-None-Match`. La app usa TanStack Query con persistencia en almacenamiento local: abre al instante con lo último conocido y refresca en segundo plano. Sin red, muestra los datos guardados con una marca «última actualización». Las imágenes se cachean en el dispositivo.

### DA-8 · Repositorio y contrato compartido — se recomienda **repositorio aparte (`SistemasANPR/eventos-app`) y OpenAPI como contrato**

La app se publica con un ciclo distinto (tiendas, EAS). El contrato vive en este repositorio como `docs/api/openapi-v1.yaml`, generado o validado en CI, y la app genera sus tipos TypeScript desde él. Una prueba en el backend verifica que las respuestas reales cumplen el esquema (evita deriva entre ambos).

### DA-9 · Imágenes — se recomienda **rutas públicas de imagen con variantes (`?w=`)**

La API devuelve URLs absolutas a las rutas de imagen existentes o equivalentes, con variantes de ancho predefinidas (320, 640, 1280) generadas en servidor y cacheadas, para no descargar originales en el teléfono. Se verifica la política de seguridad ya vigente: disco privado, nombre generado, `nosniff`, 404 uniforme.

### DA-10 · Nombre, identidad y cuentas de tienda — se recomienda **«Eventos ANPR» con cuentas de desarrollador de la organización**

Requiere Apple Developer Program y Google Play Console a nombre de la organización (no personales). Es un prerrequisito administrativo con semanas de lead time: iniciarlo en paralelo a F0. Decisión de nombre e iconografía: pendiente de Antonio.

### DA-11 · Navegación de la app — se recomienda **selector de evento al inicio y pestañas por evento**

Flujo: abrir → si ya hay un evento guardado, ir directo a él (con opción «cambiar de evento»); si no, pantalla de selección (búsqueda, código, QR). Pestañas: **Inicio** (datos y sede del evento), **Programa**, **Ponentes**, **Hospedaje**, **Patrocinadores**; las pestañas sin contenido se ocultan, igual que en el sitio web.

## 4. Contrato

### 4.1 API pública v1 (se registra en `docs/api/endpoints.md` **antes** de implementar, fase F0)

Todas bajo `/api/v1/public`, `GET`, sin autenticación, sobre `{success, data, meta}`, con `ETag`, límite estricto por IP y paginación (máximo 100). Errores: `RESOURCE_NOT_FOUND`, `VALIDATION_FAILED`, `RATE_LIMITED`; nuevo del dominio: `EVENT_UNAVAILABLE` (410, para evento archivado, según plan 032).

| Ruta | Respuesta |
|---|---|
| `/events?search=&page=&perPage=` | Eventos publicados con `listed_in_app`: `id`, `code`, `name`, `startsOn`, `endsOn`, `timezone`, `city`, `logoUrl`. Pagina. |
| `/events/by-code/{code}` | El evento por su código corto (mismo cuerpo que el detalle). |
| `/events/{event}` | Detalle: datos, sede (dirección y coordenadas si existen), idiomas ofrecidos, formato de hora, zona horaria, y un bloque `sections` con `{program, speakers, lodging, sponsors}` en booleanos para que la app oculte pestañas vacías. |
| `/events/{event}/activities?date=` | Actividades visibles, agrupables por día en la hora del evento; incluye sala, categorías y los ids de sus ponentes. Pagina. |
| `/events/{event}/activities/{activity}` | Detalle de actividad. |
| `/events/{event}/rooms` | Salas del evento. |
| `/events/{event}/speakers?page=` | Ponentes publicados. Pagina. |
| `/events/{event}/speakers/{speaker}` | Detalle de ponente con sus actividades. |
| `/events/{event}/lodgings` | Hospedajes con imágenes. |
| `/events/{event}/sponsors` | Patrocinadores agrupados por categoría (nivel). |

Idioma: `Accept-Language` (es/en) y, si falta, el idioma por defecto del evento. Los campos de texto de ponentes y actividades **no son traducibles** (plan 029): se devuelven en el idioma en que se escribieron.

**Nunca se expone:** correos, teléfonos ni datos de contacto, `contact_id`, `submission_id`, `created_by_submission_id`, registros no publicados, ocultos por reversa o borrados, ni identificadores internos secuenciales.

### 4.2 Datos (propuesta de `docs/database-design.md` §6, a redactar en F0)

- `events.public_code`: texto corto, único **global**, generado en el servidor, mayúsculas sin caracteres ambiguos; nulo hasta publicarse.
- `events.listed_in_app`: booleano, por defecto falso.
- Índices: `public_code` único; `(listed_in_app, status)` para el listado.
- Sin tablas nuevas. Migración generada por el agente; **solo el humano la aplica a una base real**.

### 4.3 Form Requests

Una clase por ruta con parámetros: `ListPublicEventsRequest` (`search` máx. 100, `page`, `perPage` ≤ 100), `ListPublicActivitiesRequest` (`date` válida), etc. Nunca `$request->all()`.

### 4.4 Contrato de la app

- `docs/api/openapi-v1.yaml` en este repositorio.
- La app genera tipos desde ese archivo; no escribe tipos a mano para la API.
- Enlaces profundos: `https://{dominio}/{organización}/events/{evento}` y `eventosanpr://event/{code}`, con archivos `apple-app-site-association` y `assetlinks.json` servidos por el sitio web (infraestructura: acción 12; la configura el humano).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Ninguno: la API pública es anónima y solo lee. El organizador decide la visibilidad con las banderas ya existentes (`is_published`, evento publicado) y la nueva `listed_in_app`. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No hay Policy: no hay acciones autenticadas. La visibilidad se impone en una capa de consulta pública única (reutilizando los constructores del plan 029) y se prueba con las pruebas de aislamiento de §7. Alta de `listed_in_app` desde el panel: permiso existente `events.manage`. |
| ¿Hay modelos nuevos con datos de una organización? | No. Dos columnas nuevas en `events`, que ya usa `BelongsToOrganization`. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Cruza **organizaciones** solo en el listado y la búsqueda de eventos (DA-2). Todo lo demás es por evento y con contexto de organización fijado. |
| ¿Se guardan datos personales nuevos? | No. La API **no expone** datos de contacto; se expone lo que el ponente aceptó publicar (nombre, empresa, cargo, biografía, foto, redes), igual que el sitio web. Revisar `docs/privacidad.md` para confirmar que el aviso cubre la publicación en la app. |
| ¿Se suben archivos? | No. Solo se sirven imágenes ya existentes (JPEG, PNG, WebP) del disco privado `media`, con `nosniff`. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | No en este plan. Push queda para un plan posterior. |
| ¿Qué se registra en la auditoría? | El cambio de `listed_in_app` y la generación de `public_code` (actividad del evento). Las lecturas públicas no se auditan; sí se miden (§8). |

Seguridad adicional: límite por IP estricto, sin CORS abierto innecesario (la app nativa no lo necesita; solo se permite el origen del sitio web), sin cookies ni sesión en `/api/v1/public`, y mensajes de error uniformes (404 igual para «no existe», «de otra organización» y «no publicado»).

## 6. Estrategia por fases

Cada fase deja su repositorio en verde y termina en su propio commit. Las fases F0 a F4 son backend (este repositorio, rama `feat/plan-036-api-publica`); F5 a F9 son la app (repositorio nuevo).

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| **F0** | Cierre de especificación: aprobar DA-1 a DA-11; registrar rutas en `docs/api/endpoints.md`; propuesta de datos §6 (`public_code`, `listed_in_app`); anotar el caso global en ADR-0003; iniciar en paralelo las cuentas de tienda (DA-10). | S |
| **F1** | Migración, generación de `public_code`, y control `listed_in_app` en el formulario del evento del panel. Pruebas. | S |
| **F2** | Núcleo de la API: grupo `/api/v1/public`, sobre de respuesta, manejo de errores JSON, límite por IP, `ResolvePublicEvent`, `ETag`. Rutas de `events` (listado, búsqueda, por código, detalle). Pruebas de aislamiento. | M |
| **F3** | Contenido por evento: actividades, salas, ponentes, hospedaje, patrocinadores. Reutiliza los constructores públicos del plan 029; paginación. | M |
| **F4** | Imágenes con variantes, contrato `openapi-v1.yaml` y prueba de conformidad. Documentación del consumo. **Hito: contrato congelado para la app.** | M |
| **F5** | Repositorio `eventos-app`: proyecto Expo con TypeScript estricto, ESLint, Vitest/Jest, CI, generación de tipos desde OpenAPI, cliente HTTP y TanStack Query. Entornos (local, staging, producción). | S |
| **F6** | Selector de evento: búsqueda, código, escáner de QR, persistencia del evento elegido, «cambiar de evento». | M |
| **F7** | Pestañas del evento: Inicio, Programa por día y detalle de actividad, Ponentes y detalle, Hospedaje, Patrocinadores. Estados de carga, vacío, error y sin conexión. | L |
| **F8** | «Mi agenda» local (favoritos de actividades en el dispositivo), caché persistente sin conexión, enlaces profundos, idiomas es/en, accesibilidad AA, diseño según `design.md`. | M |
| **F9** | Publicación: identidad visual, íconos, capturas, políticas de privacidad, compilaciones EAS, TestFlight y pruebas internas de Play, revisión de tiendas, `EAS Update` configurado. | M |

Orden de dependencias: F0 → F1 → F2 → F3 → F4 → (F5 puede empezar al terminar F2 con datos de prueba) → F6 → F7 → F8 → F9.

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-036-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo. Los CA-036-1 a 14 son del backend (Pest); del 15 al 20, de la app (repositorio aparte, con su propia verificación).

**Aislamiento y visibilidad**

- **CA-036-1:** *Dado* un evento publicado y listado de la organización A, *cuando* se consulta `GET /api/v1/public/events`, *entonces* aparece; un evento de la organización B con `listed_in_app` falso o en borrador **no** aparece.
- **CA-036-2:** *Dado* un evento de la organización B, *cuando* se pide el contenido de un ponente o actividad de la organización A usando el uuid del evento de B, *entonces* responde 404 (el binding está acotado por evento y organización).
- **CA-036-3:** *Dado* un evento archivado, borrador o inexistente, *cuando* se consulta cualquiera de sus rutas, *entonces* responde con el mismo cuerpo de error uniforme (no se distingue «no existe» de «no publicado»); el archivado responde 410 `EVENT_UNAVAILABLE` según el plan 032.
- **CA-036-4:** *Dado* un ponente o actividad no publicado, oculto por reversa, borrado o sin programar, *cuando* se consulta la lista o el detalle, *entonces* no aparece y el detalle responde 404.
- **CA-036-5:** *Dado* cualquier respuesta de la API, *entonces* no contiene `email`, teléfonos, `contact_id`, `submission_id` ni `created_by_submission_id` (prueba que recorre todas las respuestas).
- **CA-036-6:** *Dado* que no hay contexto de organización fijado, *cuando* cualquier consulta distinta de la de resolución del evento intenta leer, *entonces* la consulta **falla** (no devuelve vacío).

**Contrato**

- **CA-036-7:** *Dado* cualquier ruta, *entonces* responde con el sobre `{success, data, meta}` y los errores con `{success:false, error:{code,message}}` en JSON, aun si el cliente no envía `Accept: application/json`.
- **CA-036-8:** *Dado* `perPage=500`, *cuando* se lista, *entonces* responde 422/400 `VALIDATION_FAILED` o se limita a 100 (según lo fije el endpoint documentado); nunca devuelve más de 100.
- **CA-036-9:** *Dado* una petición con `If-None-Match` igual al `ETag` vigente, *entonces* responde 304 sin cuerpo; tras cambiar el contenido, responde 200 con un `ETag` nuevo.
- **CA-036-10:** *Dado* `Accept-Language: en` y un evento que ofrece en, *entonces* las etiquetas y datos traducibles salen en inglés; si el idioma no está ofrecido, se usa el idioma por defecto del evento.
- **CA-036-11:** *Dado* el archivo `openapi-v1.yaml`, *cuando* corre la prueba de conformidad, *entonces* todas las respuestas reales cumplen el esquema (falla si un campo se renombra o desaparece).
- **CA-036-12:** *Dado* una ruta bajo `/api/v1/public`, *entonces* está registrada en `docs/api/endpoints.md` (prueba guardiana).

**Seguridad y datos**

- **CA-036-13:** *Dado* más peticiones por IP que el límite, *entonces* responde 429 `RATE_LIMITED`.
- **CA-036-14:** *Dado* un evento recién publicado, *entonces* tiene `public_code` único global; dos eventos de organizaciones distintas nunca comparten código; la búsqueda por código es insensible a mayúsculas y responde 404 uniforme si no existe.

**App móvil**

- **CA-036-15:** *Dado* que la app se abre por primera vez, *cuando* el usuario busca su evento por nombre, por código o escaneando un QR, *entonces* lo encuentra y la app muestra su inicio.
  Manual: iOS y Android reales; probar los tres caminos y un código inexistente (mensaje de error claro, sin cierre).
- **CA-036-16:** *Dado* un evento elegido, *cuando* se reabre la app, *entonces* entra directo a ese evento y ofrece «cambiar de evento».
  Manual: cerrar y abrir la app, con y sin red.
- **CA-036-17:** *Dado* un evento sin hospedaje ni patrocinadores, *entonces* esas pestañas no aparecen.
  Manual: con un evento de prueba así.
- **CA-036-18:** *Dado* que se abrió el programa con red y luego se pierde, *entonces* la app sigue mostrando lo guardado con «última actualización»; al volver la red, se refresca sola.
  Manual: modo avión en un dispositivo real.
- **CA-036-19:** *Dado* un enlace `https://.../{org}/events/{evento}` compartido, *cuando* se toca con la app instalada, *entonces* abre ese evento directamente; sin la app, abre el sitio web.
  Manual: iOS y Android.
- **CA-036-20:** *Dado* una actividad marcada en «mi agenda», *entonces* permanece tras cerrar la app y se ve en una vista propia ordenada por hora; no se envía nada al servidor.
  Manual: marcar, cerrar, reabrir.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Fuga entre organizaciones por la consulta global de eventos (primera superficie que cruza organizaciones). | Una sola clase con `withoutOrganizationScope` documentada en ADR-0003; contexto fijado antes de cualquier otra lectura; CA-036-1, 2, 5 y 6; revisión con `/revisar-aislamiento` antes de integrar F2 y F3. |
| Exponer datos personales o internos por un campo olvidado. | Constructores explícitos (lista blanca de campos) y la prueba que recorre las respuestas (CA-036-5); revisión contra `docs/privacidad.md`. |
| Rechazo de las tiendas (política de contenido, privacidad, «app envoltura» de un sitio web, Apple 4.2). | App nativa real con caché, QR, favoritos y enlaces profundos; política de privacidad publicada; TestFlight y pruebas internas antes de enviar; iniciar cuentas y revisión con holgura. |
| Plazo de cuentas de desarrollador y verificación de organización (D-U-N-S en Apple). | Empezar el trámite en F0, en paralelo. |
| App instalada con versión vieja frente a un cambio de API. | Solo cambios aditivos en v1; `X-App-Version`; `426` reservado; CA-036-11 detecta deriva. |
| Carga por polling de muchos asistentes durante el evento (picos). | `ETag`/304, `Cache-Control`, caché en el borde (CDN) para las rutas públicas, límite por IP; medir antes del primer evento real y registrar la cifra. |
| Abuso o scraping del listado. | Límite por IP, `perPage` ≤ 100, sin exponer datos internos; solo se listan eventos con `listed_in_app`. |
| Contenido de ponentes y actividades en un solo idioma (no traducible). | Se documenta; la app no inventa traducciones. Mejora posible en un plan de contenido traducible. |
| Dos repositorios que se desincronizan. | OpenAPI como contrato, tipos generados, prueba de conformidad en CI del backend, y versión mínima de app. |
| Deuda: SSR diferido (plan 029 DA-9) no afecta a la API, pero sí a la indexación web. | Sin acción en este plan; registrado como independiente. |

Deuda que este plan propone abrir en `docs/sprints/deuda-tecnica.md` al terminar: caché en el borde (CDN) para la API pública; textos de ponentes y actividades traducibles; push y cuentas de asistentes (plan siguiente).

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
