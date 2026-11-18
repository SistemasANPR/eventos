# fix: los idiomas del evento guían todo el sistema

> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-18
- **Estado:** Aprobado (2026-11-18: especificación, DA-1 a DA-3 del usuario; DA-4 y DA-5 con la recomendación)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Al crear o editar un evento se marcan sus idiomas (`events.locales`) y el idioma por defecto (`events.default_locale`). Esa elección debe guiar a todo el sistema: si el evento es solo en español, ningún formulario, acción ni validación del evento pide ni acepta contenido en otro idioma. Hoy la regla se cumple en unas pantallas y en otras no.

**Dentro:**
- Una sola fuente de verdad de los idiomas del evento y un ayudante de Filament que la use.
- Constructor de formularios (etiqueta, ayuda y opciones), plantillas de formulario y tipos de propuesta predeterminados: solo los idiomas del evento.
- Validación en el servidor: las reglas aceptan solo las claves de los idiomas del evento y las Actions descartan las de más.
- Cambiar los idiomas de un evento ya creado: se puede quitar un idioma con advertencia y confirmación; nada se borra.
- Sitio público: sin selector de idioma cuando el evento tiene uno solo.
- Prueba guardiana que impide volver a recorrer la lista completa de idiomas en pantallas y Requests.

**Fuera:**
- Plantillas de correo: son de la organización y no del evento (es/en fijos); se dejan como están (decisión del usuario).
- Ponentes, actividades y sus categorías (plan 021): son de un solo idioma y no se traducen (decisión del usuario).
- Agregar un idioma nuevo al sistema (pt ya existe; no se añaden otros).
- Traducir automáticamente contenido.

## 2. Punto de partida

Medido el 2026-11-18 sobre `staging` (`c176bca`):

- `Event.locales` y `Event.default_locale`; `CallForSubmissions.locales` es un subconjunto de los del evento (`StoreCallForSubmissionsRequest`: `Rule::in($event->locales)`).
- **Respetan los idiomas del evento** (campos ocultos con `visible()`): nombre y descripción del evento (`EventForm`), ejes (`TrackFields`), tipos (`SubmissionTypeFields`), aviso de privacidad (`PrivacyNoticesRelationManager`) y título y descripción de la convocatoria (`CallsRelationManager`).
- **No lo respetan:** el constructor de formularios (`FormDefinitionForm::localizedTexts` y `optionLabels` recorren `StoreEventRequest::LOCALES` sin filtrar).
- **Validación abierta a los tres idiomas:** `StoreTrackRequest`, `StoreSubmissionTypeRequest`, `StoreRubricRequest`, `StorePrivacyNoticeRequest` y `FormDefinitionRules` validan contra `StoreEventRequest::LOCALES`, no contra los idiomas del evento. Ocultar un campo no impide guardar ese idioma por otra vía.
- `FormTemplates` y `DefaultSubmissionTypes` crean siempre es, en y pt.
- `UpdateEvent` valida que `default_locale` esté en `locales`, pero no revisa convocatorias ni avisos que usan un idioma que se quita.
- La lista de idiomas se repite como `foreach` en cada pantalla: no hay una fuente única.

## 3. Decisiones

- **DA-1 · Quitar un idioma en uso: permitir con advertencia (decisión del usuario).** Cerrada el 2026-11-18. Al guardar, si el idioma que se quita lo usa una convocatoria o un aviso de privacidad, se muestra qué se afecta y se pide confirmación (mismo patrón que `confirmSlugChange`); sin confirmar no se guarda. Los textos guardados en ese idioma se conservan, ocultos y sin mostrarse, para poder recuperarlos si el idioma se vuelve a marcar. El idioma por defecto no se puede quitar (ya lo exige la validación).
- **DA-2 · Plantillas en un evento de un solo idioma: solo ese idioma (decisión del usuario).** Cerrada el 2026-11-18. `FormTemplates` y `DefaultSubmissionTypes` se crean únicamente con los idiomas del evento; si falta la traducción de un idioma ofrecido, se usa el del idioma por defecto del evento o es.
- **DA-3 · Correos y programa fuera de alcance (recomendación aceptada).** Cerrada el 2026-11-18.
- **DA-4 · Qué ve el público cuando se quita un idioma.** Se recomienda **idiomas efectivos de una convocatoria = los de la convocatoria que siguen en el evento; si queda ninguno, el idioma por defecto del evento**. Un aviso de privacidad se exige solo en los idiomas efectivos. Una propuesta ya enviada en un idioma retirado se sigue viendo, con el texto de su idioma si existe o con el idioma por defecto. Cerrada el 2026-11-18 con la recomendación.
- **DA-5 · Descarte de claves de más al guardar.** Se recomienda que las Actions descarten (no rechacen) las claves de idiomas no ofrecidos de lo que llega del cliente, para que un formulario viejo abierto no falle; la validación sí rechaza claves desconocidas fuera de los tres idiomas del sistema. Cerrada el 2026-11-18 con la recomendación.

## 4. Contrato

- Sin rutas JSON ni webhooks nuevos (`docs/api/endpoints.md` no cambia) y **sin migración**: no hay datos persistentes nuevos.
- `Event::offeredLocales(): list<string>` (los idiomas del evento, con el por defecto primero) y `Event::effectiveLocalesForCall(CallForSubmissions): list<string>` (DA-4).
- Ayudante de Filament que crea un campo por cada idioma ofrecido (obligatorio solo en el por defecto) y sustituye los `foreach` actuales.
- Form Requests: las reglas de texto por idioma se construyen con los idiomas del evento (`array:es` en un evento solo en español).
- `UpdateEvent::handle` recibe una confirmación opcional de idiomas (como `confirmSlugChange`) y lanza una excepción de dominio con el detalle de lo afectado si falta.
- Prueba guardiana de Arch: prohíbe recorrer `StoreEventRequest::LOCALES` en `app/Filament` y `app/Http/Requests` fuera de una lista blanca explícita.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Sin cambios: editar un evento sigue exigiendo el permiso de editar eventos. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | La de `Event` (`update`), ya probada; se añade la prueba de la confirmación al quitar un idioma. |
| ¿Modelos nuevos con datos de una organización? | No. |
| ¿El acceso cruza eventos? | No. |
| ¿Datos personales nuevos? | No. |
| ¿Se suben archivos? | No. |
| ¿Dinero, pagos o webhooks? | No. |
| ¿Correos o notificaciones? | No (los correos quedan fuera, DA-3). |
| ¿Qué se registra en la auditoría? | `event_updated` ya existe; se añade en sus propiedades los idiomas quitados y agregados. |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F1 | Fuente única (`Event::offeredLocales`, `effectiveLocalesForCall`) y ayudante de Filament; constructor de formularios, plantillas y tipos predeterminados solo con los idiomas del evento | M |
| F2 | Validación en el servidor (reglas por idioma del evento, descarte de claves de más) y confirmación al quitar un idioma con advertencia y auditoría | M |
| F3 | Idiomas efectivos de la convocatoria y del aviso al publicar y mostrar; selector de idioma oculto con un solo idioma; prueba guardiana de Arch; verificación manual en navegador | S |

## 7. Criterios de aceptación

- **CA-022-1:** *Dado* un evento solo en español, *cuando* se abre el constructor de formularios para crear o editar un campo, *entonces* solo aparecen los campos de etiqueta, ayuda y opciones en español.
- **CA-022-2:** *Dado* un evento en español e inglés, *cuando* se abre ese mismo constructor, *entonces* aparecen los dos idiomas y solo el idioma por defecto es obligatorio.
- **CA-022-3:** *Dado* un evento solo en español, *cuando* se envía por cualquier vía un eje, tipo, rúbrica, aviso o campo de formulario con texto en inglés, *entonces* el texto en inglés se descarta y el guardado conserva solo el español (DA-5).
- **CA-022-4:** *Dado* cualquiera de esas entradas con una clave de idioma que no es es, en ni pt, *cuando* se envía, *entonces* se rechaza con error de validación.
- **CA-022-5:** *Dado* un evento solo en español, *cuando* se crea un formulario desde una plantilla o se siembran los tipos de propuesta predeterminados, *entonces* solo llevan texto en español (DA-2).
- **CA-022-6:** *Dado* un evento con una convocatoria o un aviso de privacidad que usan un idioma, *cuando* se edita el evento y se quita ese idioma sin confirmar, *entonces* no se guarda y el error lista lo que se afecta.
- **CA-022-7:** *Dado* el mismo caso, *cuando* se confirma, *entonces* se guarda, el idioma desaparece de los formularios, los textos de ese idioma se conservan y la auditoría registra los idiomas quitados.
- **CA-022-8:** *Dado* un idioma quitado y vuelto a marcar, *cuando* se abre un formulario con textos de ese idioma, *entonces* los textos conservados reaparecen.
- **CA-022-9:** *Dado* un evento cuyo idioma por defecto se intenta quitar, *cuando* se guarda, *entonces* se rechaza (ya existe; se mantiene la prueba).
- **CA-022-10:** *Dado* una convocatoria cuyos idiomas quedan todos fuera del evento, *cuando* se muestra o se publica, *entonces* sus idiomas efectivos son el idioma por defecto del evento y el aviso de privacidad se exige solo en ellos (DA-4).
- **CA-022-11:** *Dado* un evento con un solo idioma, *cuando* se abren la página del evento y la de la convocatoria, *entonces* no se muestra el selector de idioma.
- **CA-022-12:** *Dado* el código de `app/Filament` y `app/Http/Requests`, *cuando* corre la prueba de Arch, *entonces* falla si una pantalla o Request recorre la lista completa de idiomas del sistema sin estar en la lista blanca.
- **CA-022-13:** *Dado* un evento con uno, dos o tres idiomas, *cuando* se recorren las pantallas del panel que piden texto traducible a ~375 y ~1280 px con la consola abierta, *entonces* solo aparecen campos de los idiomas del evento y no hay errores.
  Manual: crear un evento solo en español, abrir Formularios (campo nuevo), Ejes, Tipos de propuesta, Aviso de privacidad y Convocatoria a ~375 y ~1280 px; repetir con español e inglés; quitar el inglés con una convocatoria que lo usa y comprobar la advertencia.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Pérdida de textos al quitar un idioma | No se borra nada: solo se ocultan (DA-1, CA-022-7 y 8) |
| Formularios del navegador abiertos con claves de un idioma ya quitado | Las Actions descartan las claves de más (DA-5) |
| Una convocatoria publicada queda sin aviso de privacidad en un idioma | Los idiomas efectivos solo exigen aviso en los idiomas que siguen en el evento (DA-4) |
| Datos antiguos con idiomas de más en formularios ya guardados | Se conservan en base y se ocultan; no hay migración |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 | `978fde6` | 2026-11-18 | Suite completa: 1879 pasan, 26 fallan, todas ajenas (exportación CA-009-11..19 y SubmissionListTest CA-009-19 por falta del paquete Excel; CA-003-11/14 de PrivacyNoticePanelTest; Arch `preset → security` por `assert()` en ViewSubmission). PHPStan: solo errores de Maatwebsite/PhpSpreadsheet ajenos. Pint y `check:criteria` en verde (0 errores). Pendiente de F2: al guardar un formulario desde el panel, los textos de un idioma quitado salen del estado del formulario (campos no creados) y se perderían: conservarlos en `UpdateFormDefinition` (DA-1, CA-022-7). `EventForm` no cambia: sus idiomas salen del estado en vivo del propio formulario del evento. |
