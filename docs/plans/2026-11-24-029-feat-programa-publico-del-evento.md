# feat: Programa público del evento (E-19): páginas de programa y ponentes

> Plan 029. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 029: todos sus criterios son `CA-029-n`. Cubre **solo E-19** (programa y ponentes en el sitio público). La agenda visual con salas, arrastrar y soltar, choques y versiones (E-20) queda fuera y es otro plan.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-24
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna abierta. Este plan propone dos puntos nuevos (§10) y toca la decisión diferida de SSR (RC-5; `docs/plan-de-desarrollo.md`, ADR-0004 DA-1).

## 1. Objetivo y alcance

El plan 021 dejó en el panel las **actividades** (charlas, paneles, talleres) y los **ponentes** de cada evento, con la marca `is_published` y la reversa que oculta lo de una propuesta retirada. Nada de eso se ve todavía fuera del panel: el visitante de `/{organización}/events/{evento}` no puede consultar qué se presenta, cuándo ni quién lo presenta. Este plan publica ese contenido en el sitio público, con solo lo que es público y sin tocar ningún dato personal que no deba salir.

**Dentro:**
- Página **Programa** del evento: actividades publicadas y programadas, agrupadas por día en la hora local del evento, con la hora en su formato de 12 h o 24 h (plan 023).
- Página **Ponentes** del evento: lista paginada de ponentes publicados.
- Página de **detalle de ponente** (biografía, redes, sus actividades) y de **detalle de actividad** (descripción, categorías, ponentes).
- Una sola capa de consulta pública que acota por organización y evento y filtra por visibilidad; todo lo que viaja al cliente sale de un constructor explícito de datos públicos.
- Enlaces a Programa y Ponentes desde la página del evento, solo si hay algo que mostrar.
- Textos de interfaz en es y en (y el comportamiento de respaldo para otros idiomas), metadatos de página y estados vacío y de error.

**Fuera:**
- **E-20**: agenda visual con salas, arrastrar y soltar, detección de choques, versiones del programa y exportación. Este plan **no** introduce salas, bloques de horario (`ScheduleBlock`) ni `Schedule`.
- Bloques de landing `program` y `speakers` (DA-1): quedan para un plan posterior; no se cambia la lista blanca de `LandingBlockType` (plan 022, aún en curso).
- Activar SSR de Inertia (DA-9): decisión propia, con ADR.
- Favoritos, «mi agenda», registro a una actividad, boletos, PWA.
- Edición de la ficha por el ponente (portal del ponente), traducción de los textos de ponentes y actividades por idioma (hoy son de un solo idioma).
- Datos estructurados JSON-LD, mapa de sitio y exportación a calendario (`.ics`).
- Cualquier migración: no se cambian tablas (§4.4).

## 2. Punto de partida

Medido el 2026-11-24 sobre `staging` (`eb0eb0b`):

- **Datos (plan 021, terminado).** `EventActivity` (`event_activities`): `title`, `summary`, `description` (HTML saneado), `starts_on`/`ends_on` (`date`) y `starts_at`/`ends_at` (`time`) locales del evento y sin zona (las cuatro o ninguna), `icon_path`, `image_path`, `is_published`, `hidden_by_reversal_at`, borrado lógico y `submission_id` (interno). `Speaker`: `first_name`, `last_name`, `email`, `country`, `company`, `job_title`, `tagline`, `bio` (HTML saneado), `photo_path`, `social_links` (`[{network, url}]`), `is_published`, `hidden_by_reversal_at`, borrado lógico, `contact_id` y `created_by_submission_id` (internos). Categorías: `ActivityCategory` y `SpeakerCategory`, por evento. Relación `activity_speaker` con `position`. Índices ya existentes: `event_activities (organization_id, event_id, starts_on, starts_at)` y `speakers (organization_id, event_id, last_name, first_name)`.
- **Los textos de ponentes y actividades no son traducibles** (columnas simples): cada registro está en el idioma en que el organizador lo escribió.
- **Reversa (plan 021, DA-3).** `HideProgramOfSubmission` pone `is_published = false` y `hidden_by_reversal_at`; publicar a mano (`SetActivityPublication`, `SetSpeakerPublication`, `UpdateActivity`, `UpdateSpeaker`) lo limpia. Hoy, por tanto, «oculto por reversa» implica «no publicado»; este plan comprueba **ambas** condiciones de todos modos (defensa en profundidad).
- **Sitio público.** `routes/web.php`: grupo `{organization:slug}` con `SetOrganizationFromRoute` (organización activa o 404, contexto de organización fijado antes del binding) y `scopeBindings()`; el evento de otra organización responde 404. `ShowEventController` hace `abort_unless($event->isPublished(), 404)`, resuelve idioma con `PublicEventData::resolveLocale` (`?lang=` si el evento lo ofrece, luego `Accept-Language`, luego el idioma por defecto) y entrega `Events/Show`. Las rutas de imagen de ponentes y actividades (`public.speakers.photo`, `public.activities.media`) comprueban evento publicado y registro publicado y no borrado, usan el uuid como identificador y responden 404 uniforme (`docs/api/endpoints.md`).
- **Aislamiento.** `BelongsToOrganization` aplica un scope global que **falla** sin contexto de organización (ADR-0003). `Speaker` y `EventActivity` lo usan.
- **Hora.** `EventTime` (PHP) y `resources/js/lib/date-format.ts` (`hourOptions` con `h23` o 12 h) ya formatean según `events.time_format` y `events.timezone`. `PublicEventData` envía `timeFormat` y `timezone`.
- **Idiomas de interfaz.** Existen `lang/es` y `lang/en`; **no hay `lang/pt`** (AGENTS.md menciona `pt`; `LangParityTest` vigila la paridad entre los directorios existentes). Los textos públicos viven en `lang/{idioma}/public.php` y llegan como props compartidas de Inertia.
- **SSR.** El plan 022 (DA-9) y el plan de desarrollo (RC-5) lo mantienen **diferido hasta R3**; el ADR-0004 DA-1 recomienda activarlo y E-19 lo pide para que «programa, ponentes y sede» sean indexables. `resources/views/app.blade.php` usa `@inertiaHead` y no hay `ssr.tsx`. Es una discrepancia entre documentos que este plan no resuelve por su cuenta (DA-9).
- **Caché.** La aplicación no tiene caché de lectura para páginas públicas; las imágenes usan `Cache-Control: public, max-age=300`. Solo `Cache::remember` en miniaturas del panel y `Cache::add` en Turnstile.
- **Landing.** El evento puede tener landing publicada (plan 022) o la plantilla fija (`EventHero`); `Events/Show` ya distingue ambos casos.

## 3. Decisiones

Todas son **recomendaciones de este plan, abiertas hasta que se apruebe el plan** (se cierran con la fecha y la opción elegida, como en los planes anteriores).

### DA-1 · Página propia o bloque de landing — se recomienda **páginas propias**

Opciones: (a) páginas propias `program` y `speakers` con enlaces desde la página del evento; (b) bloques `program` y `speakers` en el editor de la landing (plan 022). La landing es **por evento y por versión publicada**, con una lista blanca cerrada de 9 tipos; un bloque nuevo toca el enum, `LandingBlockRules`, el resolutor, el editor visual y el glosario, y el plan 022 sigue **en curso** (F5 y F6 pendientes). Un programa con decenas de actividades, filtros y paginación no cabe como bloque de una página; además necesita direcciones propias para compartirse e indexarse. Recomendación: (a) ahora, y un bloque «Resumen del programa» como plan posterior cuando el 022 cierre. Esto deja la landing intacta.

### DA-2 · Páginas de detalle — se recomienda **sí, para ponente y para actividad**

Opciones: (a) solo listas con tarjetas; (b) listas más detalle. La biografía y la descripción son texto enriquecido largo; en una tarjeta obligan a truncar o a desplegables con JavaScript. El detalle da enlace compartible por ponente y por actividad y es la unidad natural de indexación. El identificador en la dirección es el **uuid** del registro (los mismos que ya viajan en las rutas de imagen del plan 021; no son adivinables). Alternativa descartada: `slug` por ponente y actividad, que exige migración y reglas de unicidad.

### DA-3 · Qué actividades son visibles — se recomienda **publicada, no oculta por reversa, no borrada y programada**

Una actividad es visible al público si `is_published = true`, `hidden_by_reversal_at IS NULL`, `deleted_at IS NULL` y **tiene fecha y hora** (`starts_on` no nulo). Lo último es una restricción **añadida** a lo pedido: una actividad nacida de una propuesta aceptada se crea publicada y «sin programar» (plan 021, DA-2 y DA-4); mostrarla sin fecha publicaría el título y los ponentes de propuestas aceptadas antes de que el organizador arme el horario. Opciones para las «sin programar»: (a) ocultarlas hasta tener fecha (recomendada); (b) listarlas en una sección «Por confirmar» al final. Con (a) su detalle responde 404 y no aparecen en la ficha del ponente. Se revisa si el organizador necesita (b).

### DA-4 · Cómo se muestra el programa — se recomienda **un día por vista, con navegación entre días**

Una sola lista con todo el evento incumple «listas paginadas, máximo 100» en congresos grandes. Recomendación: `?day=AAAA-MM-DD` elige el día; sin valor, o con uno que no sea un día con actividades, se muestra **el primer día** (valor inválido no da error: se ignora). La página incluye la lista de días (cada uno es un enlace) y, dentro del día, las actividades en orden; si un solo día superara 100 actividades se pagina con `?page=` (tope 100). Es compatible con móvil (una columna) y con indexación (cada día tiene dirección). Alternativa descartada: todo el evento en una página con anclas por día (rompe el tope de 100 y pesa en móvil).

### DA-5 · Zona horaria y formato de hora — se recomienda **no convertir: la hora local del evento viaja tal cual**

`starts_on`/`starts_at` ya son locales del evento (plan 021, DA-12): el servidor **no** los convierte a UTC ni a la zona del visitante; envía `date: 'AAAA-MM-DD'` y `startTime: 'HH:MM'`. El cliente da formato con `Intl` fijando `timeZone: 'UTC'` sobre un instante construido a partir de esos valores (así el navegador del visitante no los desplaza) y usa el `timeFormat` del evento (12 h o 24 h, plan 023). La zona del evento se muestra **una vez** en la cabecera del programa («Hora de Ciudad de México, GMT-6»), calculada con `Intl` y `timezone` del evento. Una actividad que cruza la medianoche o dura varios días aparece bajo su **día de inicio** y muestra la fecha de fin (CA-029-13).

### DA-6 · Qué datos de ponente se exponen — se recomienda **lista cerrada; nunca correo, teléfono ni país**

**Se exponen:** uuid (solo para la dirección de su página), nombre completo, organización (`company`), cargo (`job_title`), descripción de una línea (`tagline`), biografía (HTML re-saneado al salir), fotografía (URL de `public.speakers.photo`, nunca la ruta del disco), categorías de ponente (nombre) y redes sociales (`network` y `url`, solo `https`). **Nunca se exponen:** `email`, teléfono (no existe en la ficha y no se añade), `country`, `contact_id`, `created_by_submission_id`, `organization_id`, `event_id`, `photo_path`, `hidden_by_reversal_at`, `is_published`, `deleted_at`, marcas de tiempo ni nada de la propuesta (respuestas, archivos, evaluaciones). `country` queda fuera por minimización: la política de privacidad lo lista entre los datos de la ficha pero no lo cita como visible; si el organizador lo pide, se incorpora con una línea en `docs/privacidad.md` y no cambia la estructura. Para actividades: uuid, título, resumen, descripción (HTML re-saneado), fecha y horas, categorías, URLs de icono e imagen y ponentes visibles (mismo subconjunto); nunca `submission_id`, rutas ni banderas internas.

### DA-7 · Ponentes en una actividad y actividades en un ponente — se recomienda **solo lo visible**

Una actividad visible muestra únicamente a sus ponentes visibles (publicado, no oculto, no borrado, del mismo evento); un ponente despublicado desaparece de la actividad sin ocultar la actividad. La ficha de un ponente muestra solo sus actividades visibles (DA-3). Un ponente publicado sin ninguna actividad visible **sí** aparece en la lista de ponentes (es un invitado ya anunciado, plan 021, DA-8).

### DA-8 · Caché — se recomienda **sin caché de aplicación en esta entrega**

Cada página se resuelve con una o dos consultas indexadas sobre un evento acotado (el programa de un congreso son cientos de filas, no miles) y debe reflejar de inmediato una despublicación (privacidad: lo retirado no debe seguir visible). Se mantiene el comportamiento de las rutas de imagen (`max-age=300`). **Si se añade caché más adelante**, es condición de este plan que: la clave incluya la organización y el evento y el idioma (`program:{organization_id}:{event_id}:{locale}:{version}`); la invalidación esté en **un solo punto** invocado por las Actions que escriben (alta, edición, publicación, eliminación y asignación de ponentes de actividades y ponentes, categorías, `PromoteSubmissionToProgram`, `HideProgramOfSubmission` y cambios del evento que afectan la vista: estado, zona, formato de hora, idiomas); y exista una prueba de que una despublicación nunca se sirve desde la caché y de que una organización nunca lee la clave de otra. Mientras no haya caché, CA-029-20 vigila que lo retirado se ve retirado.

### DA-9 · SEO y SSR — se recomienda **metadatos con `<Head>` ahora; SSR en su propio ADR antes de R3**

Hay un conflicto documentado: ADR-0004 DA-1 y E-19 piden SSR; el plan 022 DA-9 y RC-5 lo difieren a R3. Activarlo exige un proceso Node en producción, cambios de despliegue y operación (acciones 12 y 14 de `AGENTS.md`) y no es de este plan. Recomendación: (a) construir las páginas **compatibles con SSR** (sin `window` ni `document` al renderizar; formato con `Intl`), con `<Head>` completo (título, descripción, canonical, `hreflang` de los idiomas del evento y `og:*`), igual que `Events/Show`; (b) dejar la activación de SSR como decisión de Gabriel con ADR, dentro de R3. Mientras tanto la indexación depende de que el buscador ejecute JavaScript; se declara como riesgo (§8). Opción alternativa si SEO sin JS es crítico ya: renderizar título, descripción y `canonical` desde la vista raíz Blade (toca `app.blade.php`, compartida por todo el sitio): se descarta por alcance, queda registrada.

### DA-10 · Idiomas — se recomienda **interfaz en el idioma pedido; contenido tal como se guardó; respaldo al idioma por defecto**

El idioma se resuelve con `PublicEventData::resolveLocale` (igual que las demás páginas públicas) y sigue el selector `LocaleSwitcher`. Cambia la **interfaz** (títulos, etiquetas, estados); los textos de ponentes y actividades no se traducen porque hoy no son traducibles (§2). Una clave que falte en el idioma pedido cae al idioma base de la aplicación (`app.fallback_locale`). Los textos nuevos se agregan en `lang/es/public.php` y `lang/en/public.php`; **no existe `pt`**, así que un evento en portugués muestra la interfaz en el respaldo hasta que alguien cree `lang/pt` (fuera de este plan; se anota como deuda, §10). La prueba verifica esa conducta de respaldo, no la presencia de `pt`.

### DA-11 · Orden — se recomienda **determinista y estable**

- Programa: `starts_at` ascendente dentro del día, luego `ends_at`, luego `title` y luego `id` (desempate estable para que la paginación no repita ni pierda filas). Días en orden ascendente.
- Ponentes: apellido y luego nombre sin distinguir mayúsculas, luego `id`. El orden alfabético de acentos depende del _collation_ de PostgreSQL; si en F1 se comprueba que ordena mal los acentos del español, se ordena con `lower()` sobre la columna y se anota (sin migración).
- Ponentes de una actividad: `position` del pivote (el orden que asignó el organizador).
- Categorías: `position`, luego `name`.

### DA-12 · Filtro por categoría — se recomienda **en el cliente y sobre el día mostrado**

Botones de alternancia (`aria-pressed`) por categoría de actividad sobre las actividades del día ya recibidas; sin recarga y sin parámetros de URL. Se descarta el filtro en el servidor (más rutas, más validación, y el tope de 100 ya acota el día). Sin buscador de texto en esta entrega.

### DA-13 · Estados de la página — se recomienda **los de una página pública con datos en el servidor**

Las páginas reciben todos los datos en la respuesta, así que no hay carga asíncrona propia: la espera es la navegación de Inertia, con el indicador que ya usa el sitio, y las imágenes se cargan con `loading="lazy"` y tamaño reservado para evitar saltos de diseño. **Vacío:** evento publicado sin nada visible, 200 con mensaje y `noindex`. **Error:** 404 uniforme para lo inexistente o no público (misma página de error que el resto del sitio) y la página de error 500 estándar ante un fallo; un error de base **no** se degrada a lista vacía (invariante de AGENTS.md). **Permisos:** no aplica, el acceso es público.

## 4. Contrato

### 4.1 Rutas web (todas `GET`, sin autenticación, sin JSON)

Dentro del grupo existente `{organization:slug}` con `SetOrganizationFromRoute` y `HandleInertiaRequests`, `scopeBindings()`:

| Ruta | Nombre | Controlador (delgado) | Página Inertia |
|---|---|---|---|
| `events/{event:slug}/program` | `events.program` | `Public\ShowEventProgramController` | `Events/Program` |
| `events/{event:slug}/speakers` | `events.speakers.index` | `Public\ShowEventSpeakersController` | `Events/Speakers` |
| `events/{event:slug}/speakers/{speaker}` | `events.speakers.show` | `Public\ShowEventSpeakerController` | `Events/Speaker` |
| `events/{event:slug}/activities/{activity}` | `events.activities.show` | `Public\ShowEventActivityController` | `Events/Activity` |

`speaker` y `activity` llegan como `string`; si no es un uuid, 404 sin consultar (`Str::isUuid`, igual que los controladores de imagen). Convención de rutas: sin colisión con `speakers/{speaker}/photo` ni con `activities/{activity}/{type}` (esta última restringe `type` a `icon|image`). El evento no publicado, archivado, inexistente o de otra organización responde 404; igual la organización suspendida. Todas aceptan `?lang=`; `program` acepta `?day=AAAA-MM-DD` y `?page=`; `speakers`, `?page=`. Las rutas no escriben nada y no llevan `PreventCaching` (no dependen de la sesión).

**No hay rutas JSON ni webhooks**, por lo que `docs/api/endpoints.md` **no cambia por este plan** (solo si se añadiera una ruta JSON). Las cuatro rutas web se documentan en la propia especificación y en el código; si Gabriel quiere listarlas, se añade una sección «Páginas públicas del programa (no JSON)» junto a la de imágenes, en F5.

### 4.2 Capa de consulta (backend)

Sin cambiar firmas existentes:
- Scopes `visibleToPublic()` en `EventActivity` (publicada, no oculta, programada; el borrado lógico ya lo excluye) y `Speaker` (publicado, no oculto), más constantes de página (`PER_PAGE`).
- Actions en `app/Actions/Program/`, con `handle()`: `ListPublicProgramDay`, `ListPublicSpeakers`, `FindPublicSpeaker`, `FindPublicActivity`. Todas reciben el `Event` ya acotado por el binding, repiten `where('event_id', …)` explícito y **dependen del contexto de organización** (si falta, lanzan, no devuelven vacío). Cargan relaciones con `with()` acotadas (categorías, ponentes visibles con su pivote) para no hacer N+1.
- Constructores de datos públicos en `app/Support/Program/`: `PublicActivityData` y `PublicSpeakerData`, con la forma de §4.3 y `RichTextSanitizer::clean` sobre `bio` y `description` al salir (como `ResolveLandingBlocks`). Nada de `toArray()` de modelos.

### 4.3 Props (TypeScript, `resources/js/types/program.ts`)

```ts
interface PublicCategory { id: string; name: string }
interface PublicSpeakerSummary { id: string; name: string; company: string | null; jobTitle: string | null; tagline: string | null; photoUrl: string | null; url: string }
interface PublicSpeaker extends PublicSpeakerSummary { bioHtml: string | null; categories: string[]; socialLinks: { network: string; url: string }[] }
interface PublicActivitySummary { id: string; title: string; summary: string | null; date: string /* AAAA-MM-DD */; endDate: string; startTime: string /* HH:MM */; endTime: string; categories: PublicCategory[]; speakers: PublicSpeakerSummary[]; iconUrl: string | null; url: string }
interface PublicActivity extends PublicActivitySummary { descriptionHtml: string | null; imageUrl: string | null }
interface Paginated<T> { data: T[]; currentPage: number; lastPage: number; prevUrl: string | null; nextUrl: string | null }
```

- `Events/Program`: `event: PublicEvent`, `organization`, `days: string[]`, `day: string | null`, `activities: Paginated<PublicActivitySummary>`, `categories: PublicCategory[]` (solo las usadas ese día), `nav: { programUrl, speakersUrl }`, `privacyUrl`.
- `Events/Speakers`: `event`, `organization`, `speakers: Paginated<PublicSpeakerSummary>`, `nav`, `privacyUrl`.
- `Events/Speaker`: `event`, `organization`, `speaker: PublicSpeaker`, `activities: PublicActivitySummary[]`, `nav`.
- `Events/Activity`: `event`, `organization`, `activity: PublicActivity`, `nav`.
- `Events/Show` gana `programUrl: string | null` y `speakersUrl: string | null` (nulos si no hay nada visible, como `privacyUrl`).

Todas las URL se construyen con `route(..., false)` (relativas), como el resto del sitio. Los textos nuevos van en `lang/{es,en}/public.php` (no se escribe texto en los componentes).

### 4.4 Datos

**Sin migraciones ni cambios de esquema.** Las columnas y los índices que se necesitan existen (§2): el filtro por evento y visibilidad usa `(organization_id, event_id, starts_on, starts_at)` y `(organization_id, event_id, last_name, first_name)`. F1 verifica con `EXPLAIN` sobre datos de prueba que las consultas del programa y de ponentes usan esos índices; si no fuera así, se **propone** el cambio con la plantilla de `docs/database-design.md` §6 y no se aplica sin aprobación (acción 1 de `AGENTS.md`).

### 4.5 Componentes de interfaz

Antes de crear nada, F0 revisa el catálogo (`design.md` §1.4). Reutilizar: `PublicLayout`, `EventLogo`, `RichText`, `TextLink`, `LocaleSwitcher`, `PageBanner`, `date-format.ts`. Nuevos (con entrada en `catalog/registry.ts` y demo): `ProgramDayNav` (molécula, enlaces a días), `ActivityCard` y `SpeakerCard` (moléculas), `CategoryFilter` (molécula, botones con `aria-pressed`), `EventSectionNav` (molécula: Evento, Programa, Ponentes). Una variante faltante se añade al componente existente. Nuevo helper en `date-format.ts`: `formatLocalTime(hhmm, locale, timeFormat)` y `formatLocalDate(date, locale)`, ambos con `timeZone: 'UTC'` (DA-5).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Lectura pública, sin sesión ni roles. No hay escritura ni acción de panel nueva: publicar y ocultar siguen siendo del plan 021 (`activities.manage`, `speakers.manage`) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No aplica Policy: son rutas públicas de solo lectura. La barrera es la **visibilidad pública** (`visibleToPublic`, DA-3 y DA-6) y el 404 uniforme, probados en `tests/Feature/Public/`. No se cambia ninguna regla de autorización (acción 9) |
| ¿Hay modelos nuevos con datos de una organización? | No. Se reutilizan `EventActivity`, `Speaker` y categorías, que ya usan `BelongsToOrganization`; la prueba de aislamiento del núcleo ya los recorre |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No depende de asignaciones. **Nunca cruza eventos**: toda consulta lleva `event_id` y la organización del contexto. Un uuid de ponente o actividad de otro evento, aunque sea de la misma organización, responde 404 (CA-029-5) |
| ¿Se guardan datos personales nuevos? | **No se guarda nada nuevo.** Se **publican** datos que ya existen: nombre, organización, cargo, descripción, biografía, fotografía, redes (base: consentimiento expreso para la publicación, `docs/privacidad.md`, fila de la ficha del ponente). **No se publican** correo, país ni identificadores internos (DA-6). La baja se atiende en el panel (despublicar u eliminar) y, sin caché de aplicación, surte efecto en la siguiente petición. F5 actualiza `docs/privacidad.md` para decir qué campos de la ficha son públicos |
| ¿Se suben archivos? | No. Las imágenes se sirven por las rutas del plan 021, que ya comprueban publicado y no borrado en disco privado |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | Nada: lectura pública. No se registra IP ni datos del visitante; el registro de acceso es el del servidor web |

Reglas de seguridad de la salida (`docs/seguridad.md`): `bio` y `description` se **vuelven a sanear** al salir y se pintan con el componente `RichText` (sin `dangerouslySetInnerHTML` suelto); los enlaces de redes sociales solo se emiten si son `https` (los demás se omiten en silencio); los enlaces externos llevan `rel="noopener noreferrer nofollow"`; la respuesta de las páginas no lleva `Set-Cookie` propio ni datos de sesión. Las páginas son de solo lectura, así que no añaden superficie de CSRF; no se añade un límite de peticiones nuevo más allá del que ya tenga el sitio público (se anota como riesgo, §8).

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit. Orden fijo: la visibilidad y el aislamiento se prueban **antes** que cualquier interfaz.

| Fase | Qué se hace | Criterio de terminado | Esfuerzo |
|---|---|---|---|
| F0 | Aprobar este plan y cerrar DA-1 a DA-13. Revisar el catálogo de componentes (`design.md` §1.4), confirmar los idiomas que puede ofrecer un evento (`es`, `en`, `pt`) frente a los directorios de `lang/`, y añadir al glosario «Programa público» (vista pública de las actividades visibles; no es `Schedule`) | Plan en `Aprobado`; decisiones registradas en §3 y en el commit; glosario al día; `composer check` en verde | S |
| F1 | **Prueba primero.** Scopes `visibleToPublic`, Actions de consulta y constructores `PublicActivityData` y `PublicSpeakerData`; pruebas de visibilidad, aislamiento, falla sin contexto, lista cerrada de campos, orden y N+1. Verificar índices con `EXPLAIN` | CA-029-1 a 11 y 24 verdes en `tests/Feature/Public/` y `tests/Unit/`; `composer check` en verde con su salida; sin migraciones | M |
| F2 | Rutas, controladores y página **Programa** (días, tarjetas, filtro de categoría, hora con formato del evento, estados vacío y 404), helpers `formatLocalTime` y `formatLocalDate`, textos es/en | CA-029-1, 12 a 15, 17, 19 verdes; `npm run typecheck`, `lint`, `test` y `build` en verde; capturas a 375 y 1280 px sin errores en consola | L |
| F3 | Páginas **Ponentes**, **ficha de ponente** y **detalle de actividad**; paginación; redes y biografía saneadas | CA-029-8 a 11, 16 verdes; verificación en navegador a 375 y 1280 px | M |
| F4 | Enlaces `programUrl` y `speakersUrl` en `Events/Show` (con y sin landing), `EventSectionNav`, metadatos (`<Head>`, canonical, `hreflang`, `noindex` en vacío), entradas en el catálogo de componentes y respaldo de idioma | CA-029-15, 18 y 19 verdes; prueba de catálogo y `conventions.test.ts` en verde | M |
| F5 | Verificación manual completa (CA-029-21 a 23), `docs/privacidad.md` (campos públicos de la ficha), sección opcional en `docs/api/endpoints.md`, puntos de deuda de §10 en `docs/sprints/deuda-tecnica.md` (commit `docs(debt)` aparte) y cierre del plan | Todos los criterios con prueba o `Manual:` registrada; `composer check` y `npm run typecheck && lint && test && build` con salida real; revisión del diff; plan en `Terminado` | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-029-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Visibilidad y datos**

- **CA-029-1:** *Dado* un evento publicado con actividades visibles en dos días, *cuando* un visitante abre `events/{evento}/program`, *entonces* recibe 200 con la lista de días en orden ascendente y, para el día mostrado, solo las actividades de ese día ordenadas por hora de inicio, luego de fin, título e identificador.
- **CA-029-2:** *Dado* actividades con `is_published = false`, con `hidden_by_reversal_at` no nulo (aunque `is_published` sea `true`), con borrado lógico, o publicadas de un evento distinto, *cuando* se abre el programa, la ficha de un ponente o el detalle, *entonces* ninguna aparece y el detalle de cada una responde 404.
- **CA-029-3:** *Dado* una actividad publicada y «sin programar» (sin fecha ni hora), *cuando* se abre el programa, el detalle o la ficha de sus ponentes, *entonces* no aparece en ninguna lista y su detalle responde 404 (DA-3).
- **CA-029-4:** *Dado* un evento en borrador, archivado, inexistente o de otra organización, o una organización suspendida, *cuando* se piden las cuatro rutas, *entonces* todas responden 404 con la misma respuesta que un slug desconocido, sin revelar si el recurso existe.
- **CA-029-5:** *Dado* dos organizaciones con eventos del mismo slug o con actividades y ponentes de títulos y nombres iguales, *cuando* se pide el programa o la lista de ponentes de una, *entonces* nunca aparece un registro de la otra; y un uuid de actividad o ponente de otro evento (de la misma o de otra organización) responde 404 en el detalle.
- **CA-029-6:** *Dado* que no hay organización en contexto, *cuando* se ejecuta una Action de consulta pública, *entonces* lanza la excepción del contexto ausente y **no** devuelve una lista vacía.
- **CA-029-7:** *Dado* un ponente y una actividad con todos sus campos llenos (incluidos correo, país, `contact_id`, propuesta de origen y rutas de archivo), *cuando* se construyen las props, *entonces* las claves son **exactamente** las de §4.3 y no hay ninguna cadena con el correo, el país, un `organization_id`, un `event_id`, un `submission_id` ni una ruta de disco.
- **CA-029-8:** *Dado* un evento con más ponentes que el tamaño de página, *cuando* se abre `events/{evento}/speakers`, *entonces* recibe a lo más 100 (48 por defecto) ordenados por apellido y nombre sin distinguir mayúsculas, con enlaces de página siguiente y anterior correctos, y solo ponentes publicados, no ocultos y no borrados.
- **CA-029-9:** *Dado* un ponente visible, *cuando* se abre su ficha, *entonces* muestra nombre, organización, cargo, descripción, biografía saneada, fotografía por la ruta pública, categorías, redes `https` y **solo** sus actividades visibles en orden; un ponente no publicado, oculto por reversa, borrado, de otro evento o con identificador que no es uuid responde 404.
- **CA-029-10:** *Dado* una actividad visible con tres ponentes de los que uno está despublicado, *cuando* se abre su detalle, *entonces* muestra los otros dos en el orden de `position` y la actividad sigue visible.
- **CA-029-11:** *Dado* un ponente publicado sin ninguna actividad visible, *cuando* se abre la lista de ponentes, *entonces* aparece, y su ficha muestra el estado «sin actividades programadas» en vez de una sección vacía.

**Hora, día e idioma**

- **CA-029-12:** *Dado* un evento en `America/Mexico_City` con una actividad local `2026-11-20` de 23:30 a 23:59, *cuando* se abre el programa desde un navegador en cualquier zona, *entonces* aparece bajo el 20 de noviembre y con la hora 23:30 sin desplazarse, y el formato es el del evento: `11:30 p. m.` con 12 h o `23:30` con 24 h.
- **CA-029-13:** *Dado* una actividad que empieza el día 20 a las 22:00 y termina el día 21 a las 02:00, *cuando* se abre el programa, *entonces* aparece solo bajo el día 20 y muestra la fecha de fin; el día 21 no la repite.
- **CA-029-14:** *Dado* `?day=` con un día que tiene actividades, con uno sin actividades o con un valor que no es fecha, *cuando* se abre el programa, *entonces* el primero se muestra y los otros dos caen al primer día del programa sin error ni 404.
- **CA-029-15:** *Dado* un evento que ofrece es y en, *cuando* se pide `?lang=en`, `?lang=pt` (no ofrecido) o sin parámetro con `Accept-Language`, *entonces* la interfaz sale en en, en el idioma por defecto del evento y en el mejor idioma ofrecido respectivamente, una clave faltante cae al respaldo de la aplicación, y los textos de ponentes y actividades se muestran tal como se guardaron.

**Salida y estados**

- **CA-029-16:** *Dado* una biografía con `<script>` o un enlace `javascript:` y una red social con `http://` o con un esquema distinto, *cuando* se construyen las props y se pinta la ficha, *entonces* el script y el enlace peligroso no llegan al cliente, la red no `https` se omite y los enlaces externos llevan `rel="noopener noreferrer nofollow"`.
- **CA-029-17:** *Dado* un evento publicado sin ninguna actividad ni ponente visible, *cuando* se abren `program` y `speakers`, *entonces* responden 200 con un mensaje de «el programa se publicará pronto» y la etiqueta `noindex`, sin tabla ni lista vacías.
- **CA-029-18:** *Dado* una página con contenido, *cuando* se renderiza, *entonces* el `<title>` incluye el nombre del evento y de la organización, hay descripción, `canonical` (con `?lang=` solo si no es el idioma por defecto) y un `hreflang` por cada idioma del evento, y `og:title`.
- **CA-029-19:** *Dado* un evento con y sin actividades o ponentes visibles, *cuando* se abre `events/{evento}` (con y sin landing), *entonces* `programUrl` y `speakersUrl` son la URL solo si hay contenido visible y `null` si no, y el enlace no aparece cuando es nulo.
- **CA-029-20:** *Dado* una actividad visible, *cuando* un organizador la despublica o la oculta la reversa, *entonces* la petición siguiente a programa, detalle y ficha de sus ponentes ya no la muestra (no hay caché de aplicación, DA-8).
- **CA-029-24:** *Dado* un evento con 50 actividades y 20 ponentes, *cuando* se abre el programa, la lista de ponentes y una ficha, *entonces* el número de consultas no crece con el número de filas (sin N+1; el límite exacto se fija en la prueba al medirlo en F1).

**Interfaz (pruebas de componentes y comprobación manual)**

- **CA-029-21:** *Dado* el programa de un día con actividades de varias categorías, *cuando* el visitante activa una categoría, *entonces* solo se ven las actividades con esa categoría, el botón tiene `aria-pressed="true"`, desactivarla las muestra todas y el filtro se puede operar con teclado. Se prueba con Vitest.
- **CA-029-22:** *Dado* el programa, la lista de ponentes y el detalle, *cuando* se ven a 375 px y a 1280 px, *entonces* no hay desplazamiento horizontal, las tarjetas pasan a una columna en móvil, los enlaces tienen un área de toque de al menos 44 px, el contraste cumple AA, los encabezados siguen el orden `h1`, `h2`, `h3`, la fotografía tiene texto alternativo (o vacío si es decorativa), el foco es visible y la navegación de días indica el día actual con `aria-current`, y la consola no muestra errores.
  Manual: abrir `/{organización}/events/{evento}/program`, `/speakers`, una ficha y un detalle con datos de prueba a 375 y 1280 px, navegar solo con teclado y revisar la consola; comprobar también con el tema oscuro. Esperar o hacer scroll antes de concluir un fallo por animaciones de entrada.
- **CA-029-23:** *Dado* el flujo completo en navegador con un evento de prueba en `staging`, *cuando* el organizador despublica una actividad y un ponente y reaccionan las tres páginas, *entonces* los dos desaparecen en la siguiente carga y la fotografía despublicada deja de servirse en 5 minutos como máximo (caché de imagen del plan 021).
  Manual: repetir con la actividad y el ponente de una propuesta revertida desde el panel; anotar en §9 los tiempos observados.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| **Fuga entre organizaciones** por una consulta que omita el filtro o una relación cargada sin scope (por ejemplo ponentes cargados por el pivote) | Scope global que falla cerrado; `where('event_id')` explícito en cada Action; `with()` con la misma restricción de visibilidad y de evento; CA-029-5 y 6; revisión con `/revisar-aislamiento` antes de cerrar F1 |
| **Publicar un dato personal que no debía** (correo, país, propuesta de origen) | Constructores explícitos y lista cerrada de campos (DA-6); CA-029-7 compara las claves exactas; nunca `toArray()` de un modelo |
| Que una actividad aceptada pero **sin programar** se publique por accidente | DA-3: no visible hasta tener fecha; CA-029-3 |
| **Una despublicación sigue visible** por caché | Sin caché de aplicación (DA-8); `max-age=300` solo en imágenes, ya documentado; CA-029-20 y 23. Si se añade caché, condiciones de DA-8 |
| Las rutas de imagen del plan 021 **no comprueban** `hidden_by_reversal_at` ni «programada» | Hoy la reversa también pone `is_published = false`, así que es inalcanzable; se anota como deuda (§10) para endurecerlas en otro plan |
| **SEO limitado sin SSR**: un buscador que no ejecute JavaScript no ve el contenido | Declarado en DA-9; páginas compatibles con SSR; metadatos con `<Head>`; la activación de SSR es decisión de Gabriel con ADR antes de R3 |
| Un día con más de 100 actividades o un evento con cientos de ponentes | Paginación con tope 100 y orden estable con `id` (CA-029-1 y 8) |
| Orden alfabético incorrecto con acentos (_collation_ de PostgreSQL) | Se mide en F1; si falla, `lower()` y se anota; sin migración |
| Hora desplazada por la zona del navegador del visitante | La hora local viaja sin convertir y se formatea con `timeZone: 'UTC'` (DA-5); CA-029-12 con zonas distintas |
| Contenido enriquecido con HTML peligroso | Re-saneado al salir y componente `RichText` (CA-029-16) |
| Rastreo masivo de las páginas públicas (sin límite nuevo) | Consultas acotadas e indexadas, sin escritura; se revisa un límite por IP con el resto del sitio público (§10) |
| Que se cuele E-20 (salas, choques) | Fuera de alcance (§1); cualquier campo de sala o bloque va a otro plan |
| Idioma `pt` sin archivos de traducción | Respaldo explícito (DA-10, CA-029-15); deuda anotada |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | — | — | Pendiente de aprobación |

## 10. Deuda que deja (a registrar en F5)

- Endurecer `ShowSpeakerPhotoController` y `ShowActivityMediaController` para comprobar también `hidden_by_reversal_at` (y «programada» en actividades), con su prueba. Prioridad P3, esfuerzo S.
- Crear `lang/pt` (público y panel) o decidir formalmente que el producto ofrece solo es y en. Prioridad P3, esfuerzo M. Requiere decisión de producto.
- Activar SSR de Inertia (ADR-0004 DA-1 contra RC-5): ADR y plan de despliegue antes de R3. Prioridad P2, esfuerzo L.
- Límite de peticiones por IP del sitio público de solo lectura, junto con el de las demás páginas públicas. Prioridad P3, esfuerzo S.
- Bloques de landing `program` y `speakers` (DA-1) y E-20 (agenda con salas y choques): planes propios.
