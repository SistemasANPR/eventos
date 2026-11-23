# feat: Logotipo del evento

> Plan 027. Especificación (SDD, ADR-0001).

- **Fecha:** 2026-11-20
- **Estado:** Terminado (2026-11-23: F1 a F3 construidas y registradas en §9)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Al crear un evento no se puede subir su logotipo, y tampoco al editarlo. Este plan añade un campo opcional **«Logotipo»** en el formulario de crear y de editar un evento, y muestra el logotipo en las páginas públicas del evento.

**Dentro:**
- Campo opcional «Logotipo» al crear y al editar un evento, con vista previa, cambiar y quitar.
- Guardado seguro de la imagen en el disco privado `media` (mismo patrón de los ponentes, plan 021).
- Ruta pública que sirve el logotipo solo si el evento está publicado.
- Mostrarlo en la página del evento, en la de la convocatoria y en la de gracias.

**Fuera:**
- Logotipo en los correos (exige una dirección pública estable de la imagen); se verá aparte.
- Logotipo de la organización (hoy el sitio muestra el nombre de la organización) y marca blanca (ADR-0004, DA-4).
- Recorte o edición de la imagen en el panel; formatos vectoriales (SVG) y animados.

## 2. Punto de partida

Medido el 2026-11-20 sobre `staging`:
- `Event` y su formulario (`EventForm`) no tienen ningún campo de imagen; las páginas públicas del evento y de la convocatoria muestran solo el nombre de la organización y el del evento.
- El plan 021 dejó el patrón reutilizable: disco `media` (`MediaDisk`, variable `MEDIA_DISK`), `StoreProgramImage` con `ProgramImageType`, validación por contenido (JPEG, PNG y WebP, tope de píxeles, sin SVG), nombre generado bajo `organizations/{id}/events/{id}/media/...` y rutas públicas que solo sirven lo publicado (`ShowSpeakerPhotoController`, `ShowActivityMediaController`).
- Los datos del evento viajan al sitio público por `PublicEventData`, que se arma campo por campo (CA-002-15).

## 3. Decisiones

Todas cerradas el 2026-11-20 por Gabriel (delegación de AGENTS.md).

### DA-1 · Dónde se guarda — **una columna `logo_path` en `events`**
Una columna `string` nullable con la ruta en el disco `media`; no hace falta una tabla aparte porque un evento tiene un solo logotipo. Alternativa descartada: una tabla de imágenes; es más estructura de la que se necesita para un único archivo.

### DA-2 · Formatos y límites — **PNG, JPEG y WebP, hasta 2 MB**
Validados por su contenido real (no por el nombre ni el tipo que declara el cliente), con el tope de píxeles de `StoreProgramImage`. **No se acepta SVG** (`docs/seguridad.md`: puede traer código). Se reutiliza `StoreProgramImage` con un tipo nuevo `event_logo`.

### DA-3 · Reemplazar y quitar — **se puede cambiar y quitar; el archivo anterior se borra**
Subir un logotipo nuevo borra el anterior del disco en la misma operación. «Quitar» deja el evento sin logotipo y borra el archivo. Si falla algo a mitad, no queda el archivo huérfano ni el evento apuntando a uno inexistente.

### DA-4 · Cómo se sirve — **ruta pública solo con el evento publicado**
`GET /{organization}/events/{event}/logo` responde la imagen con `X-Content-Type-Options: nosniff` y caché corta, solo si el evento está publicado y tiene logotipo; si no, 404 sin distinguir los casos. El panel ve la imagen con una ruta autenticada, de modo que un evento en borrador no la filtra.

### DA-5 · Dónde se muestra — **eventos, convocatoria y gracias**
En la cabecera de la página pública del evento (y, cuando exista, en la landing del plan 022), en la de la convocatoria y en la de gracias. El texto alternativo es el nombre del evento. Sin logotipo, las páginas quedan como hoy.

### DA-6 · Permisos — **`events.manage`**
Quien puede editar el evento puede subir, cambiar y quitar su logotipo. No hay permiso nuevo ni cambia la matriz de roles.

## 4. Contrato

- **Datos:** una migración nueva que añade `events.logo_path` (`string`, nullable). No toca las existentes ni requiere migrar datos.
- **Formulario:** `FileUpload` de Filament con el guardado a cargo de una Action (el formulario no escribe en el disco por su cuenta). Las reglas de la entrada viven en `StoreEventRequest` / la Action de actualizar, con su prueba.
- **Acciones** (`app/Actions/Events/`): `StoreEventLogo` y `RemoveEventLogo`; `CreateEvent` y `UpdateEvent` las invocan.
- **Ruta pública nueva:** `public.events.logo`, que se documenta en `docs/api/endpoints.md`. No es JSON.
- **Props:** `PublicEventData` añade `logoUrl` (cadena o `null`); no expone la ruta de disco ni identificadores internos.

### 4.1 Propuesta de datos (`docs/database-design.md` §6)
1. **Necesidad:** el logotipo de un evento; dueño: módulo de eventos; ciclo de vida: se sube al crear o editar, se reemplaza o se quita, y se borra con el evento.
2. **Inspección:** tabla `events` (sin imagen) y el patrón de archivos del plan 021.
3. **Modelo:** columna `logo_path` nullable en `events` (ámbito: evento).
4. **Integridad:** el archivo vive en el disco `media` bajo la ruta de su organización y evento; la columna solo guarda la ruta.
5. **Cambio:** una migración que añade la columna, reversible y sin datos que migrar. La aplica una persona en una base real (en Railway: `php artisan migrate --force`).
6. **Alternativa descartada:** una tabla de imágenes (DA-1).
7. **Preguntas de negocio:** ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Subir, cambiar y quitar el logotipo: `events.manage` (DA-6). El visitante lo ve solo con el evento publicado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | La Policy del evento (`update`); una prueba por rol, permitido y denegado |
| ¿Modelos nuevos con datos de una organización? | No: es una columna de `Event`, que ya usa `BelongsToOrganization` |
| ¿Cruza eventos u organizaciones? | No. Un evento de otra organización responde 404 al subir, quitar o pedir su logotipo |
| ¿Datos personales nuevos? | No. Un organizador podría subir una imagen con personas: es contenido público que él decide publicar |
| ¿Se suben archivos? | Sí: PNG, JPEG y WebP, hasta 2 MB, disco `media` privado, nombre generado, validación por contenido, sin SVG |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos? | No |
| ¿Qué se registra en la auditoría? | `event_logo_changed` y `event_logo_removed`, con identificadores y sin contenido |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F1 | Datos y servidor: migración, tipo `event_logo`, Actions `StoreEventLogo` y `RemoveEventLogo`, ruta pública, `logoUrl` en `PublicEventData`, endpoints y pruebas | M |
| F2 | Panel: campo «Logotipo» en crear y editar, vista previa, cambiar y quitar | S |
| F3 | Sitio público: logotipo en la página del evento, la de la convocatoria y la de gracias | S |

## 7. Criterios de aceptación

- **CA-027-1:** *Dado* un usuario con `events.manage`, *cuando* crea un evento con un logotipo PNG, JPEG o WebP válido, *entonces* el evento se crea con su logotipo guardado en el disco `media` con nombre generado, bajo la ruta de su organización y evento.
- **CA-027-2:** *Dado* un evento sin logotipo, *cuando* se crea sin subir imagen, *entonces* se crea igual y sus páginas se ven como hoy.
- **CA-027-3:** *Dado* un archivo que no es PNG, JPEG ni WebP (SVG, HTML, ejecutable o imagen con código incrustado), *cuando* se sube, *entonces* se rechaza por su contenido real aunque el nombre o el tipo declarado sean válidos, y no queda nada en el disco.
- **CA-027-4:** *Dado* una imagen de más de 2 MB o con más píxeles que el tope, *cuando* se sube, *entonces* se rechaza con un error de validación en el campo.
- **CA-027-5:** *Dado* un evento con logotipo, *cuando* se sube uno nuevo, *entonces* el anterior se borra del disco en la misma operación y el evento apunta al nuevo.
- **CA-027-6:** *Dado* un evento con logotipo, *cuando* se quita, *entonces* la columna queda vacía, el archivo se borra y se registra `event_logo_removed`.
- **CA-027-7:** *Dado* una falla a mitad de cambiar el logotipo, *cuando* ocurre, *entonces* no queda un archivo huérfano ni el evento apuntando a uno inexistente.
- **CA-027-8:** *Dado* un usuario sin `events.manage`, *cuando* intenta subir, cambiar o quitar el logotipo por el panel o por la Action, *entonces* recibe 403 y no cambia nada.
- **CA-027-9:** *Dado* un administrador de la organización A, *cuando* sube, quita o pide el logotipo de un evento de B, *entonces* responde 404 y no cambia nada.
- **CA-027-10:** *Dado* un evento publicado con logotipo, *cuando* un visitante pide `public.events.logo`, *entonces* recibe la imagen con `X-Content-Type-Options: nosniff`.
- **CA-027-11:** *Dado* un evento en borrador, archivado o sin logotipo, *cuando* un visitante pide su logotipo, *entonces* responde 404 igual en todos los casos.
- **CA-027-12:** *Dado* las props de la página pública, *cuando* se renderizan, *entonces* `logoUrl` es una dirección pública o `null`, y no incluyen la ruta del disco ni identificadores internos.
- **CA-027-13:** *Dado* un evento publicado con logotipo, *cuando* un visitante abre la página del evento, la de la convocatoria o la de gracias, *entonces* ve el logotipo con el nombre del evento como texto alternativo; sin logotipo no aparece ningún hueco ni imagen rota.
  Manual: abrir las tres páginas con y sin logotipo a ~375 px y ~1280 px y revisar la consola.
- **CA-027-14:** *Dado* el formulario de crear y de editar un evento, *cuando* se usa, *entonces* muestra el campo «Logotipo» con vista previa, cambiar y quitar.
  Manual: crear un evento con logotipo, editarlo para cambiarlo y quitarlo, y comprobar la vista previa.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Imagen con código (SVG o archivo disfrazado) | Validación por contenido, sin SVG, nombre generado y `nosniff` (CA-027-3, 10) |
| Imagen enorme que agota la memoria | Tope de bytes y de píxeles (CA-027-4) |
| Archivos huérfanos al reemplazar | Borrado en la misma operación y prueba de atomicidad (CA-027-5, 7) |
| Fuga del logotipo de un evento en borrador | La ruta pública responde 404 sin evento publicado (CA-027-11) |
| Desplegar el código antes de migrar la base de Railway | La migración la aplica una persona justo al desplegar; hasta entonces fallan las pantallas que leen la columna |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 | `21c8a60` | 2026-11-20 | Migración `2026_11_20_100000_add_logo_path_to_events_table` aplicada solo en la base local (`php artisan migrate`); **en Railway la aplica una persona: `php artisan migrate --force`, justo al desplegar**. `EventLogoTest`: 27 pruebas pasan (CA-027-1 a 12). Pint sobre los archivos propios y Larastan sobre ellos: sin errores (con un subconjunto de `tests/` Larastan avisa de un patrón ignorado no usado, ajeno al cambio). Corrida de Pest de `tests/Feature/{Events,Landings,Calls,Tenancy,Program}` y `tests/Arch`: 1033 pasan, 3440 aserciones. `composer check:criteria`: 0 errores, 12 avisos (planes anteriores, ninguno del 027). |
| F2 | `02557b9` | 2026-11-20 | `EventLogoPanelTest`: 8 pruebas pasan (CA-027-3, 8, 9, 14); con `EventPanelTest` y `LangParityTest`: 51 pasan, 168 aserciones. Pint y Larastan sin errores. Vista previa por `admin/{organización}/events/{evento}/logo` (autenticada, `no-store`). |
| F3 | `8e17fc6` | 2026-11-20 | `npm run typecheck` y `lint` sin errores; `npm run test -- --pool=threads`: 36 archivos, 250 pruebas pasan; `npm run build` correcto. Átomo `EventLogo` en el catálogo. **No ejecutado:** la comprobación manual en navegador a ~375 px y ~1280 px (CA-027-13 y 14) ni `composer check` completo ni la suite Pest completa (solo los directorios citados). |
