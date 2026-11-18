# feat: formato de hora del evento (12 h o 24 h)

> Plan 022. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 022: todos sus criterios son `CA-022-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-18
- **Estado:** En curso (aprobado: el usuario, 2026-11-18: especificación y propuesta de datos de §4; DA-1 a DA-5 como se recomiendan; alcance F0 a F4; el valor por defecto es 12 h)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

La aplicación muestra las horas con formatos distintos según la pantalla (24 h en casi todas, 12 h en alguna). El organizador necesita que todo un evento use el mismo formato. Se añade al evento la opción **formato de hora** (12 h o 24 h) y un único punto de formateo que todas las pantallas usan.

**Dentro:** campo `time_format` en el evento y su formulario; un helper único que da formato a horas y rangos según el evento; migrar a ese helper las pantallas del panel que hoy muestran horas (tarjetas y ficha del evento, periodo de convocatorias, horas de las actividades, lista y detalle de propuestas); el sitio público con la misma opción; la prueba guardiana que impide volver a formatos sueltos.

**Fuera:** el formato de **fecha** (sigue `d/m/Y` en español de México y lo que ya haya en inglés); la zona horaria (ya existe en el evento); los valores guardados (siguen en UTC y en `H:i:s`; solo cambia cómo se muestran); marcas de tiempo de auditoría y de seguridad, que siguen en UTC con `Y-m-d H:i`; los correos, hasta que el catálogo tenga plantillas con horas.

## 2. Punto de partida

Medido el 2026-11-18 sobre `staging` (`c176bca` más los cambios locales de esta sesión):

- La tabla `events` tiene `timezone`, `locales`, `default_locale` y `currency`, y no tiene ninguna opción de formato de hora (`2026_11_10_100000_create_events_table.php`).
- Lugares del panel que **muestran** horas al usuario:
  - `EventsTable::displayDates()`: `j M Y, H:i` (24 h).
  - `CallsRelationManager`: columna de periodo, ahora `d/m/Y H:i \h` desde `lang/{es,en}/calls.php` (`calls.datetime_format`).
  - `ManageEventActivities` (línea 417): hora de las actividades, con `Carbon::createFromFormat('H:i:s', …)` localizado.
  - `PrivacyNoticesRelationManager` (línea 63): fecha de publicación del aviso, etiquetada «Publicado (UTC)» a propósito (trazabilidad), por lo que **no** pasa al formato del evento.
- Lugares que **capturan** horas (entradas): `DateTimePicker` en `EventForm` y en `CallsRelationManager`, y `TimePicker` en `ManageEventActivities`. Su valor guardado es `Y-m-d H:i` o `H:i`, y no cambia.
- Sitio público: `resources/js/lib/date-format.ts` usa `Intl.DateTimeFormat` con el idioma de la página, sin forzar `hour12`. `PublicEventData` y `PublicCallData` envían instantes ISO en UTC.
- El resto de usos de `format('...H:i...')` en `app/` son formatos de almacenamiento o de validación (`date_format:Y-m-d H:i`, ISO en UTC) o de auditoría, y no son mostrados al usuario.

## 3. Decisiones

### DA-1 · Valor por defecto — **12 h** (cerrada el 2026-11-18)

Decisión del usuario. Los eventos nuevos nacen en 12 h. Ver DA-4 para los existentes.

### DA-2 · Dónde se aplica — se recomienda **panel y sitio público**

Opciones: (a) solo panel; (b) panel y sitio público. El sitio público muestra horas del evento y de la convocatoria, así que dejarlo fuera mantendría la inconsistencia que se quiere resolver. Recomendación: (b), con `PublicEventData` enviando `timeFormat` y `date-format.ts` aplicando `hour12`.

### DA-3 · Selectores de hora — se recomienda **sí, en una fase aparte (F4)**

Un evento en 12 h con un selector en 24 h sería inconsistente. Filament permite fijar el formato de **presentación** de `DateTimePicker` y `TimePicker` sin cambiar el valor guardado. Es la fase de mayor riesgo visual y se separa para poder aprobarla o aplazarla sin bloquear el resto. Recomendación: incluirla como F4, después de verla en navegador.

### DA-4 · Eventos que ya existen — se recomienda **pasan a 12 h**

Opciones: (a) a 12 h, por coherencia con el valor por defecto; (b) quedan en 24 h, que es lo que ven hoy. La migración fija el valor por defecto de la columna y no distingue filas nuevas de antiguas; (b) exige un `UPDATE` explícito. Recomendación: (a), con un aviso en la nota de la migración de que las pantallas de los eventos existentes cambiarán a 12 h al desplegar y de que cada organizador puede volver a 24 h en el evento.

### DA-5 · Cómo se formatea — se recomienda **un helper único `EventTime`**

`App\Support\Events\EventTime` recibe un instante o una hora `H:i:s` y el evento, y devuelve el texto. Una prueba guardiana impide `format('…H:i…')` y `translatedFormat` con horas fuera de él en `app/Filament/` (convención del repo: todo lo comprobable mecánicamente tiene su guardiana). Alternativa descartada: un `config` o una constante por idioma, que no ve la opción del evento.

## 4. Contrato

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** hecho de negocio: cada evento decide cómo se muestran sus horas. Módulo dueño: Eventos. Vive mientras vive el evento; no es dato personal.
2. **Inspección:** `events` (`2026_11_10_100000_create_events_table.php`) y el modelo `Event`; `StoreEventRequest`/`UpdateEvent` y `EventForm`; la Action de duplicar evento (plan 019, en borrador) debe copiar el campo.
3. **Modelo propuesto:** una columna en `events`, **ámbito: evento** (es propiedad del evento, como `timezone`). `time_format` `varchar(3)` `NOT NULL` con valor por defecto `12h`. No justifica tabla ni pivote: es un atributo simple de uno de dos valores.
4. **Integridad:** `CHECK (time_format IN ('12h', '24h'))`; enum de PHP `App\Enums\TimeFormat` con cast; sin índice (no se filtra por él); se edita con el permiso que ya cubre la edición del evento y el cambio queda en el historial de actividad que ya registra la edición del evento (se verifica en F1).
5. **Cambio:** una migración que añade la columna con su valor por defecto y el `CHECK`; las filas existentes toman `12h` (DA-4); compatibilidad: la columna tiene valor por defecto, así que el código anterior sigue funcionando durante el despliegue; reversa: `down()` elimina la restricción y la columna. Verificación: la prueba de esquema del evento, más una prueba de que un evento sin valor explícito queda en `12h`. La migración la genera el agente y **solo el humano la aplica a una base real**.
6. **Alternativa descartada:** preferencia por usuario (columna en `users`). Resuelve un gusto personal, no la coherencia de un evento: dos organizadores del mismo evento verían horas distintas, y el sitio público no sabría cuál usar.
7. **Preguntas de negocio abiertas:** ninguna que impida definir el esquema (DA-4 es la única con efecto visible sobre eventos existentes).

**Form Request:** `StoreEventRequest` (y su uso en la edición) añade `time_format` con `Rule::enum(TimeFormat::class)`, obligatorio; con prueba.

**Props del sitio público:** `PublicEventData` añade `timeFormat: '12h' | '24h'`; el tipo de TypeScript y `date-format.ts` lo reciben. No es una ruta JSON nueva, así que no cambia `docs/api/endpoints.md`.

**Textos:** claves en `lang/es`, `lang/en` y `lang/pt` para la etiqueta del campo, sus dos opciones y su ayuda («Cómo se muestran las horas de este evento»).

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Cambiar el formato: quien ya puede editar el evento. Ver las horas: quien ya ve el evento. Sin permisos nuevos |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `EventPolicy` (`update`), ya probada; se añade una prueba del campo con el rol permitido y uno denegado |
| ¿Modelos nuevos con datos de una organización? | No. Se añade una columna a `events`, que ya usa `BelongsToOrganization` |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No |
| ¿Datos personales nuevos? | No |
| ¿Se suben archivos? | No |
| ¿Dinero, pagos o webhooks? | No |
| ¿Correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | El cambio de `time_format` como cualquier otro campo del evento |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Enum `TimeFormat`, helper `EventTime` con sus pruebas (12 h y 24 h, es/en/pt, medianoche y mediodía, rangos, hora `H:i:s`) y guardiana Arch. Sin tocar pantallas ni datos | S |
| F1 | Migración y propuesta de datos; campo en el modelo, el Form Request y el formulario del evento; copia al duplicar evento (plan 019, si ya existe); pruebas de validación, permisos y valor por defecto | M |
| F2 | Migrar las pantallas del panel al helper: tarjetas de eventos, periodo de convocatorias, horas de las actividades, lista y detalle de propuestas. El aviso de privacidad se queda en UTC | M |
| F3 | Sitio público: `timeFormat` en `PublicEventData`, `date-format.ts` con `hour12`, tipos y pruebas de Vitest | S |
| F4 | Selectores de hora con el formato de presentación del evento (DA-3). Verificación en navegador a ~375 px y ~1280 px | M |

## 7. Criterios de aceptación

- **CA-022-1:** *Dado* un evento nuevo sin elegir formato, *cuando* se crea, *entonces* su formato de hora es 12 h.
- **CA-022-2:** *Dado* el formulario del evento, *cuando* se elige 24 h y se guarda, *entonces* el valor persiste y el formulario lo muestra al volver a abrirlo.
- **CA-022-3:** *Dado* un valor distinto de `12h` o `24h`, *cuando* se envía el formulario o se inserta directamente en la base, *entonces* se rechaza (validación y `CHECK`).
- **CA-022-4:** *Dado* un evento en 12 h en español, *cuando* se formatea el instante 14:30 de su zona, *entonces* el texto es `2:30 p. m.`; *dado* uno en 24 h, *entonces* es `14:30 h`.
- **CA-022-5:** *Dados* los instantes 00:00 y 12:00, *cuando* se formatean en 12 h, *entonces* son `12:00 a. m.` y `12:00 p. m.`, sin ambigüedad.
- **CA-022-6:** *Dado* un rango que cruza el mediodía, *cuando* se formatea en 12 h, *entonces* cada extremo lleva su `a. m.` o `p. m.`, y en 24 h ambos llevan solo la hora.
- **CA-022-7:** *Dado* un evento con una zona horaria distinta de UTC, *cuando* se formatea un instante guardado en UTC, *entonces* se muestra en la zona del evento, y cambiar el formato no cambia el instante guardado.
- **CA-022-8:** *Dado* un evento en 12 h, *cuando* se abren las tarjetas de eventos, el periodo de convocatorias, las actividades del programa y las propuestas, *entonces* todas muestran 12 h; con 24 h, todas muestran 24 h.
- **CA-022-9:** *Dado* un usuario sin permiso de edición del evento, *cuando* intenta cambiar el formato, *entonces* se deniega en el servidor.
- **CA-022-10:** *Dado* un evento de otra organización, *cuando* se consulta o se cambia su formato, *entonces* responde como si no existiera.
- **CA-022-11:** *Dado* el código de `app/Filament/`, *cuando* corre la guardiana, *entonces* falla si una pantalla formatea una hora de cara al usuario sin pasar por `EventTime`.
- **CA-022-12:** *Dada* la página pública de un evento, *cuando* se muestra con `timeFormat` en 12 h o en 24 h, *entonces* las horas siguen esa opción en los idiomas de la interfaz (es y en).
- **CA-022-13:** *Dado* el selector de hora de un evento en 12 h, *cuando* se usa, *entonces* se presenta en 12 h y el valor guardado sigue siendo `H:i`.
  Manual: abrir un evento en 12 h, crear una actividad y editar la apertura de una convocatoria; comprobar los selectores a ~375 px y ~1280 px con la consola abierta (DA-3, F4).

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Los eventos existentes cambian a 12 h al desplegar | DA-4 lo declara; el organizador puede volver a 24 h; se anota en la migración y en el resumen de la fase |
| `Intl` y Carbon dan variantes distintas de «a. m.» (`a. m.`, `a.m.`, `AM`) según versión de ICU | CA-022-4 y CA-022-12 fijan el resultado esperado; si difiere, se normaliza en el helper y no en cada pantalla |
| Una pantalla futura vuelve a formatear a mano | La guardiana de CA-022-11 |
| Los selectores en 12 h confunden al capturar | F4 separada, con comprobación manual y posibilidad de aplazarla |
| Choque con el plan 019 (duplicar evento) | El campo se añade a la lista de atributos copiados cuando el plan 019 exista |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | 1edc99d | 2026-11-18 | Enum `TimeFormat`, helper `EventTime` y 8 pruebas en verde (CA-022-4 a 7); Carbon da `2:30 p. m.` y `12:00 a. m.` como pide el plan. Desviación: las pruebas van en `Feature/Events` y no en `Unit`, porque el helper usa `app()` y `__()`. |
| F1 | a1e5920 | 2026-11-18 | Migración con `CHECK` y valor por defecto `12h`; 12 pruebas en verde (CA-022-1 a 3, 9 y 10); suite completa 1901 en verde. Hallazgo: la edición del evento audita `event_updated` pero no el valor de cada campo, así que el cambio de formato queda registrado como edición, sin el valor anterior. Migración aplicada solo en la base de desarrollo local. |
| F2 | 5470bf0 | 2026-11-18 | Pantallas migradas a `EventTime`; guardiana Arch CA-022-11 (detecta `H:i`, `g:i`, `LT` y `LLL`) y pruebas CA-022-8 (tarjetas, convocatorias, actividades). Desviaciones: el aviso de privacidad sigue en UTC por diseño; se añadieron la lista y el detalle de propuestas, que la guardiana encontró; el `assert()` de `ViewSubmission` se sustituyó por una excepción, lo que cerró la falla de la guardiana de seguridad. CA-022-8 de propuestas lo cubre la guardiana, sin prueba de pantalla propia. |
| F3 | a07b7f5 | 2026-11-18 | `timeFormat` en `PublicEventData` y `PublicCallData`; `date-format.ts` con `hour12` o `hourCycle: 'h23'`; typecheck y lint sin errores, 175 pruebas de Vitest en verde, build OK. Desviación: en español `Intl` escribe la hora de 24 h sin cero inicial (`9:00`), a diferencia del panel (`09:00 h`). No existe `lang/pt`, así que el criterio queda en es y en. |
| F4 | ad72ba3 | 2026-11-18 | Selectores del evento, de la convocatoria y de las actividades con `displayFormat` según el evento; 1920 pruebas en verde y 13 fallos previos de exportación (falta `maatwebsite/excel` en el contenedor). **CA-022-13 sigue sin comprobar a mano**: la extensión de Chrome no estaba conectada. Límite conocido: solo cambia el texto mostrado; el selector interno de horas de Filament sigue de 0 a 23. |
