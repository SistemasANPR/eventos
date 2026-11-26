# feat: acceso del autor a su propuesta (sesión al enviar, acuse con enlace y avisos visibles)

> Plan 034. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 034: todos sus criterios son `CA-034-n`. Amplía el plan 006 (acceso del autor sin cuenta): **no cambia el modelo de seguridad** del enlace de un solo uso, solo hace visible y directo el camino de volver a la propuesta.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-26
- **Estado:** En curso (F0 a F2 construidas y verificadas con pruebas; pendiente la comprobación manual de CA-034-19 en navegador, ver §9)
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna abierta. El plan 006 §9 deja la comprobación manual de CA-006-58 pendiente; este plan toca las mismas pantallas y la repite para ellas.

## 1. Objetivo y alcance

Hoy el autor que acaba de enviar su propuesta no tiene forma de saber que puede corregirla: la confirmación no lo menciona, el acuse va sin enlace, la página de la convocatoria no ofrece entrada y la edición solo se descubre por el recordatorio de cierre (verificado en el código el 2026-11-26). Este plan hace que el autor (a) entre directamente a su propuesta justo después de enviarla, (b) reciba en el acuse el aviso de que puede editar y hasta cuándo, con el camino para volver, (c) encuentre desde la convocatoria cómo consultar lo enviado, y (d) vea siempre en su propuesta si puede editar, hasta cuándo y, si no puede, por qué.

**Dentro:**
1. **Sesión de autor al enviar (C):** al confirmarse el envío, se abre la sesión del autor para esa propuesta y la confirmación ofrece «Ver o editar mi propuesta».
2. **Acuse con enlace a la pantalla de pedir acceso (A):** el correo `submission.received` dice que puede editar hasta el cierre y trae la dirección de `request-link` (no un token).
3. **Entrada desde la convocatoria:** enlace «¿Ya enviaste tu propuesta? Consúltala o edítala».
4. **Avisos visibles:** plazo de edición en la vista del autor; mensaje explícito tras el cierre o cuando la propuesta ya está en proceso; texto claro en la pantalla de pedir acceso sobre el correo, la caducidad y el uso único; botón «Cerrar sesión».

**Fuera:**
- Enlace de gestión duradero en el acuse (opción D) y cuentas de autor (opción E, R2).
- Verificación del correo del participante principal antes de abrir la sesión (ver DA-1).
- Cambiar la duración del enlace (30 min) o de la sesión (60 min), ni autoguardado de borradores (RC-8).
- Editar archivos (R0 no los edita; plan 006).
- Cualquier migración o tabla nueva.

## 2. Punto de partida

Medido el 2026-11-26 sobre `staging` (`a4f4b08`):

- **Envío.** `StoreSubmissionController` valida, limita, verifica Turnstile y llama a `SubmitSubmission::handle()`, que devuelve un `SubmitOutcome` (`submission`, `created`); el controlador **ignora el resultado**, fija el dato de sesión `submission_confirmed.{call}` y redirige (303) a `calls.submitted`. Una repetición con la misma clave devuelve la misma propuesta (`created = false`). La clave de idempotencia se emite al renderizar el formulario y solo su hash vive en la **sesión del navegador** (`IssueFormSession`, CA-006-15), así que una repetición solo puede venir del mismo navegador.
- **Confirmación.** `Calls/Submitted.tsx`: texto «Tu propuesta quedó registrada. Enviaremos la confirmación al correo de la persona principal.» y dos botones (volver a la convocatoria / al evento). Sin mención de edición. `noindex`.
- **Acuse.** `NotifySubmissionReceived` envía `submission.received` con las variables `author_name`, `submission_title`, `submission_reference`, `event_name`, `call_title`, `submission_type`, `track_name`. La plantilla predeterminada (`lang/es/mail_templates.php`) agradece, resume y no menciona edición ni enlaces. CA-006-29 exige «sin enlace de acceso»; su prueba (`SubmissionNotificationsTest`) comprueba que no hay token.
- **Sesión del autor.** `AuthorSession::open()` guarda solo `submission_id` y `organization_id` con caducidad absoluta de 60 min (`submissions.author.session_minutes`) y regenera el identificador de sesión. La abre hoy solo `ConsumeAccessLinkController`. `AuthorSession::close()` existe pero **ninguna ruta lo invoca**: no hay «cerrar sesión».
- **Vista del autor.** `ShowAuthorSubmissionController` ofrece `editUrl` solo si `PrepareAuthorEdit::isEditable()` (estado `submitted` y convocatoria abierta) y `withdrawUrl` si el estado es `submitted`. Cuando no hay `editUrl`, la pantalla **no dice por qué**. Muestra un aviso de «dura :minutes minutos».
- **Convocatoria pública.** `Calls/Show.tsx` ofrece el botón de envío solo con la convocatoria abierta y el enlace de privacidad; **no hay entrada a `request-link`**. `PublicCallData` ya expone `closesAt` (UTC).
- **Entradas existentes a `request-link`:** los correos de resultados, de retiro y de recordatorio de cierre, la pantalla de enlace no válido y la redirección de `AuthenticateAuthor` sin sesión.
- **Hora.** `EventTime` y `date-format.ts` formatean con la zona y el formato de hora del evento (plan 023).
- **Idiomas.** `lang/es` y `lang/en`; `LangParityTest` vigila la paridad.

## 3. Decisiones

Resumen: sesión automática al enviar; acuse con la dirección de `request-link` (sin token) y el plazo; enlace permanente en la convocatoria; aviso de estado de edición explícito; ruta propia para cerrar sesión.

### DA-1 · Abrir la sesión del autor al enviar — se recomienda **sí, con el control actual del envío y sin verificar el correo**

Quien acaba de enviar ya pasó el formulario (Turnstile, límite por IP y por correo) y escribió los datos que verá. La sesión solo da acceso a **esa** propuesta y a su edición y retiro, no a datos de terceros ni de otras propuestas, y no toca el panel (CA-006-37). Lo que se gana es no pedirle un correo más en el momento en que más corrige. Riesgo reconocido: quien escribe el correo de otra persona como «principal» queda con una sesión sobre una propuesta que él mismo creó; es el mismo acceso que ya tiene por haberla escrito y no revela nada que no haya escrito. La alternativa (no abrir sesión hasta verificar el correo) obliga a otro correo y pierde el objetivo. Se abre también ante una repetición idempotente (misma clave, mismo navegador), no ante un envío rechazado.

### DA-2 · Qué lleva el acuse — se recomienda **plazo de edición y dirección de `request-link`, nunca un token**

Dos variables nuevas e **aditivas** en `submission.received`: `request_link_url` (la pantalla de pedir acceso del evento) y `edit_deadline` (el cierre de la convocatoria en la zona del evento y en el idioma del envío, con el mismo formato que `closing_date` del recordatorio de cierre, `submission_results.closing_date_format`; `closes_at` es obligatorio en la convocatoria, así que no hay caso sin cierre). El formateo se extrae a una clase de apoyo compartida con `SendClosingReminders`, sin duplicarlo. Se actualiza la plantilla predeterminada es y en. Las plantillas que un organizador ya personalizó **no se tocan** y siguen funcionando: las variables son opcionales. CA-006-29 sigue cumpliéndose (sin enlace con token); se anota en el plan 006 que este plan la matiza.

### DA-3 · Entrada desde la convocatoria — se recomienda **enlace de texto permanente bajo el botón de envío o el estado**

Visible mientras la convocatoria sea pública (abierta, programada, pausada o cerrada), porque consultar sigue siendo posible tras el cierre. Texto: «¿Ya enviaste tu propuesta? Consúltala o edítala». Es un enlace secundario, no compite con el botón principal. No se muestra si el evento no está disponible (plan 032).

### DA-4 · Aviso de estado de edición — se recomienda **un estado explícito calculado en el servidor**

`PrepareAuthorEdit` expone, además de `isEditable()`, el motivo cuando no se puede editar: `call_closed` (la convocatoria no está abierta) o `in_process` (la propuesta ya no está en `submitted`: en revisión, decidida, retirada). La vista del autor muestra: «Puedes editar hasta {fecha y hora del evento}», o «La convocatoria cerró el {fecha}; ya no se puede editar», o «Tu propuesta ya está en proceso y no se puede editar». El servidor sigue comprobándolo todo al guardar (CA-006-42).

### DA-5 · Cerrar sesión — se recomienda **POST propio `my-submission/logout`**

En equipos compartidos, una sesión de 60 min abierta por el envío justifica un botón de salida. Ruta web (HTML, no JSON: no entra al catálogo de `docs/api/endpoints.md`), solo POST con CSRF, en el grupo con sesión de autor. Cierra con un método nuevo de `AuthorSession` que, además de olvidar la sesión, deja la auditoría `author_session_closed`; redirige a la página de la convocatoria de la propuesta (o a la del evento si la convocatoria ya no es pública).

### DA-6 · Pantalla de pedir acceso — se recomienda **explicar el mecanismo sin cambiar su respuesta**

Texto fijo: «Te enviaremos un enlace al correo con el que registraste la propuesta. Dura {N} minutos y solo se puede usar una vez.» `N` sale de `submissions.author.access_link_minutes`. La respuesta del servidor sigue siendo idéntica exista o no el correo (CA-006-32).

## 4. Contrato

- **Rutas JSON o webhooks:** ninguno.
- **Ruta web nueva:** `POST /{organization}/events/{event}/my-submission/logout` (`author.logout`), con `AuthenticateAuthor`, CSRF y las cabeceras de `ProtectAuthorPages`. Sin entrada, sin Form Request.
- **Notificación:** `submission.received` gana las variables opcionales `request_link_url` y `edit_deadline` (`NotificationDescriptor`); plantilla predeterminada es/en actualizada; prueba del catálogo al día (ADR-0009).
- **Props de Inertia** (todas aditivas):
  - `Calls/Submitted`: `authorUrl` (a `author.submission.show`, sin token) y `editDeadline` (ISO UTC o `null`) con `timezone` y `timeFormat` del evento.
  - `Calls/Show`: `authorRequestUrl`.
  - `Author/Show`: `editPolicy` = `{state: 'editable'|'call_closed'|'in_process', until: string|null}`, `timezone`, `timeFormat`, `logoutUrl`.
  - `Author/RequestLink`: sin props nuevas; ya recibe `expiresInMinutes` y `maxLinks` (`AccessLinkPageData`). Solo cambia el texto de introducción (DA-6).
- **Datos:** sin cambios. Sin migración.
- **Textos:** claves nuevas en `lang/es` y `lang/en` (`public.php`, `mail_templates.php`), con paridad.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Autor sin cuenta: ver, editar y retirar **su** propuesta con sesión de autor (sin cambios); ahora esa sesión también se abre al enviar y se puede cerrar a mano. Organizadores: sin cambios. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No hay Policy nueva: el guard `author` y `ResolveAuthorSubmission` resuelven la propuesta desde la sesión, nunca desde la URL. Se prueba en CA-034-4, 5 y 15. |
| ¿Hay modelos nuevos con datos de una organización? | No. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. La sesión lleva `organization_id` y `submission_id`; una ruta de otra organización o de otro evento no la acepta (CA-034-15). |
| ¿Se guardan datos personales nuevos? | No. El acuse y la confirmación no añaden datos: la fecha de cierre es pública y no hay identificadores en las URLs. |
| ¿Se suben archivos? | No. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Se modifica el contenido de uno existente (`submission.received`, crítico, por cola, vía `SendNotification`). No hay correos nuevos. |
| ¿Qué se registra en la auditoría? | Se añade `author_session_opened` (con `via: submit` o `via: link`) y `author_session_closed`, con `organization_id` y sin datos personales. |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit, en la rama `feat/plan-034-acceso-del-autor`, creada desde `staging`.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| **F0** | Aprobar este plan y cerrar DA-1 a DA-6. Anotar en el plan 006 (CA-006-29 y la sesión) que este plan lo amplía. | S |
| **F1** | Backend, con pruebas primero: abrir la sesión de autor en `StoreSubmissionController`; ruta y controlador de cierre de sesión; `editPolicy` en `PrepareAuthorEdit`; variables nuevas del acuse y su plantilla es/en; props nuevas de los controladores; auditoría de sesión. | M |
| **F2** | Frontend: botón y fecha en `Calls/Submitted`; enlace en `Calls/Show`; avisos de plazo y estado y botón «Cerrar sesión» en `Author/Show`; texto en `Author/RequestLink`; textos es/en; pruebas de Vitest. | M |
| **F3** | Verificación: `composer check`, `npm run typecheck && lint && test && build`; recorrido manual a ~375 px y ~1280 px con la consola abierta; cierre del plan y registro de avance. | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-034-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**Sesión al enviar**

- **CA-034-1:** *Dado* un envío válido, *cuando* se confirma, *entonces* el navegador queda con sesión de autor de esa propuesta y abrir `author.submission.show` responde 200 sin pedir enlace.
- **CA-034-2:** *Dado* una repetición idempotente (misma clave, mismo navegador), *cuando* llega, *entonces* se abre la sesión sobre la misma propuesta y no se crea otra ni se encola otro correo.
- **CA-034-3:** *Dado* un envío rechazado (validación 422, `CALL_NOT_OPEN`, `CALL_NOT_READY`, `FORM_OUTDATED`, Turnstile, límite de peticiones), *cuando* responde el error, *entonces* no se abre ninguna sesión de autor.
- **CA-034-4:** *Dado* la sesión abierta por el envío, *cuando* se intenta ver, editar o retirar otra propuesta del mismo evento por identificador, *entonces* responde 404; y *cuando* se intenta abrir una página del panel, *entonces* se deniega (CA-006-37 y CA-006-38 se mantienen).
- **CA-034-5:** *Dado* un navegador con sesión de autor de la propuesta A, *cuando* envía la propuesta B, *entonces* la sesión pasa a ser la de B, el identificador de sesión se regenera y A deja de estar abierta en ese navegador.

**Confirmación**

- **CA-034-6:** *Dado* la confirmación con sesión de autor, *cuando* se abre, *entonces* muestra «Ver o editar mi propuesta» hacia `author.submission.show` y, si hay cierre, «Puedes editar hasta {fecha y hora en la zona y el formato del evento}»; la URL no contiene datos personales ni identificadores de la propuesta (CA-006-57 se mantiene).
- **CA-034-7:** *Dado* que la sesión de autor ya caducó, *cuando* se toca el botón de la confirmación, *entonces* redirige a la pantalla de pedir acceso, sin revelar la propuesta (comportamiento de `AuthenticateAuthor`).

**Acuse**

- **CA-034-8:** *Dado* un envío correcto, *cuando* se encola `submission.received`, *entonces* las variables incluyen `request_link_url` (a `author.request-link.show` del evento) y `edit_deadline` en la zona y el formato de hora del evento; el cuerpo no contiene ningún token ni `access_url` (CA-006-29 se mantiene).
- **CA-034-9:** *Dado* una convocatoria que cierra a una hora cuyo día cambia según la zona (p. ej. 2027-02-16 02:00 UTC con evento en `America/Mexico_City`), *cuando* se compone el acuse en es y en en, *entonces* `edit_deadline` muestra el cierre en la zona del evento y en el idioma del envío, con el mismo formato que `closing_date` del recordatorio de cierre.
- **CA-034-10:** *Dado* una plantilla del organizador que no usa las variables nuevas, *cuando* se envía el acuse, *entonces* se envía igual, sin error.
- **CA-034-11:** *Dado* la plantilla predeterminada, *entonces* existe en es y en, menciona la edición y su plazo, y la prueba del catálogo de notificaciones pasa.

**Convocatoria y pantalla de acceso**

- **CA-034-12:** *Dado* una convocatoria pública abierta, programada, pausada o cerrada, *cuando* se abre su página, *entonces* aparece «¿Ya enviaste tu propuesta? Consúltala o edítala» hacia `request-link`; *cuando* el evento no está disponible, *entonces* no hay página ni enlace (plan 032).
- **CA-034-13:** *Dado* la pantalla de pedir acceso, *entonces* explica que el enlace llega al correo, que dura `expiresInMinutes` minutos y que es de un solo uso (antes de enviar el correo, no solo después); y *cuando* se envía un correo con propuesta y uno sin ella, *entonces* la respuesta sigue siendo idéntica (CA-006-32).

**Vista del autor**

- **CA-034-14:** *Dado* una propuesta `submitted` con la convocatoria abierta, *cuando* el autor abre su propuesta, *entonces* `editPolicy.state` es `editable`, ve «Puedes editar hasta {fecha}» y el botón de editar; con la convocatoria cerrada, el estado es `call_closed` y ve «La convocatoria cerró el {fecha}; ya no se puede editar»; con la propuesta en revisión, decidida o retirada, el estado es `in_process` y ve el aviso de proceso. En ningún caso el botón de editar aparece fuera de `editable` y el servidor sigue rechazando la edición (CA-006-42).

**Aislamiento y cierre de sesión**

- **CA-034-15:** *Dado* una sesión de autor abierta en la organización A, *cuando* se usa una ruta de `my-submission` de la organización B, *entonces* no concede acceso (404 o redirección a pedir acceso).
- **CA-034-16:** *Dado* una sesión de autor, *cuando* se hace POST con CSRF a `author.logout`, *entonces* la sesión se cierra, se audita y redirige a la convocatoria; *cuando* se hace GET, *entonces* responde 405; y después `author.submission.show` redirige a pedir acceso.
- **CA-034-17:** *Dado* abrir y cerrar sesión de autor, *entonces* la auditoría registra `author_session_opened` (con `via: submit` o `via: link`) y `author_session_closed`, sin datos personales.

**Idiomas y accesibilidad**

- **CA-034-18:** *Dado* los textos nuevos, *entonces* existen en es y en con paridad (`LangParityTest`) y todo texto visible usa i18n.
- **CA-034-19:** Las pantallas tocadas (confirmación, convocatoria, vista del autor y pedir acceso) funcionan a ~375 px y ~1280 px, con foco visible, contraste AA, teclado completo y sin errores de consola.
  Manual: enviar una propuesta de prueba en local (Mailpit en http://localhost:8025 para el acuse) y recorrer confirmación → «Ver o editar mi propuesta» → editar → «Cerrar sesión» → convocatoria → «¿Ya enviaste…?» → pedir acceso, a 375 px y a 1280 px, en es y en en.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Sesión de autor abierta en un equipo compartido tras el envío. | Caducidad absoluta de 60 min, botón «Cerrar sesión» visible (DA-5) y la sesión solo da acceso a esa propuesta. |
| Alguien envía con el correo de otra persona y queda con sesión sobre esa propuesta. | Es el mismo acceso que ya tiene por haberla escrito; la sesión no muestra datos que no haya escrito (DA-1). El límite por correo (3 acuses por hora, CA-006-26) y Turnstile siguen vigentes. |
| El acuse se interpreta como enlace de acceso y se reenvía. | Lleva solo la dirección de la pantalla de pedir acceso, nunca un token (CA-034-8). |
| Plantillas personalizadas por organizadores no muestran el plazo. | Las variables son opcionales y la plantilla predeterminada sí las usa; se avisa en la documentación de notificaciones. |
| Dos sesiones en el mismo navegador (el autor envía dos propuestas). | La última sustituye a la anterior (CA-034-5); la anterior se recupera pidiendo un enlace. |
| Cambiar `StoreSubmissionController` rompe la idempotencia (CA-006-14 a 16). | Las pruebas de idempotencia existentes deben pasar sin cambios; se abre la sesión solo después de que `SubmitSubmission` devuelve. |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 | de13a2b | 2026-11-26 | Aprobado por Antonio el 2026-11-26 (DA-1 a DA-6 con la recomendación). Ajustes a lo medido en el código: `closes_at` es obligatorio (CA-034-9 sin caso «sin cierre»), `AccessLinkPageData` ya expone `expiresInMinutes` (CA-034-13) y el cierre de sesión audita con un método nuevo de `AuthorSession`. |
| F1 | 282277f, d59c96b, aa2be1c | 2026-11-26 | Sesión de autor al enviar, `author.logout` con auditoría, acuse con `edit_deadline` y `request_link_url` (formateo compartido en `CallClosingDate`), `PrepareAuthorEdit::policy`, props de confirmación y convocatoria. Pruebas escritas primero (rojo visto en sesión, acuse y props); las del cierre de sesión se escribieron tras la ruta. Larastan: sin errores. Pint al día. |
| F2 | e2da10f | 2026-11-26 | `Calls/Submitted`, `Calls/Show` (página y landing), `Author/Show`, `Author/RequestLink`, textos es/en. `npm run typecheck` y `npm run lint` sin errores; Vitest 50 archivos / 375 pruebas en verde; build con `npx vite build --configLoader runner` (con `npm run build` falla el EACCES conocido de `node_modules/.vite-temp` del contenedor). |
| F3 | pendiente | | Suite completa de PHP, `composer check:criteria` y la comprobación manual de CA-034-19 a ~375 px y ~1280 px con la consola abierta (el navegador de Playwright lo usaba otra sesión y la extensión de Chrome no conectó). |
