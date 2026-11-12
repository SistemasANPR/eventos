# feat: correos, catálogo de notificaciones y plantillas

> Plan de la épica E-08 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. El nivel evento en la interfaz, el logotipo y colores, pt, versiones, envío de prueba y registro de envíos son del plan 013 (R1).

- **Fecha:** 2026-11-09
- **Estado:** En curso
- **Esfuerzo:** L
- **Deuda relacionada:** RC-6 (edición de plantillas por organización como «Debería»)

## 1. Objetivo y alcance

Un módulo **transversal** donde vive todo correo que dispara el sistema. Cada módulo registra sus notificaciones en un catálogo central en código; cada notificación tiene una plantilla predeterminada del sistema en español e inglés; la organización puede (si cabe en R0) adaptar asunto, cuerpo y remitente. El envío es por cola, con reintentos e idempotencia, y el proveedor (Resend primero, SES después) es intercambiable sin tocar los módulos. El cpanel de origen no enviaba ningún correo desde el panel.

**Dentro:** catálogo en código y guardiana; plantillas predeterminadas es/en; renderizado seguro (Markdown primero, valores escapados después) con variables de lista blanca; envío por cola con adaptador Resend e interfaz para SES; límites por destinatario; los correos mínimos de R0 (§7.5 del plan maestro más `team.membership_added`); como «Debería», la pantalla del catálogo con estado, la personalización por organización con vista previa y restaurar, y activar o desactivar no críticas.

**Fuera:** pantalla de plantilla por evento, logotipo, colores, pie y firma, idioma pt (la columna lo admite desde R0, las predeterminadas llegan en R1), historial de versiones, envío de prueba, registro de envíos con estado de entrega y adaptador SES (plan 013, R1); campañas masivas (E-29); correos de evaluación y resultados (planes 011 y 012, que registran sus claves en este catálogo).

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-008-1 Como equipo, quiero un catálogo en código de notificaciones, para que cada módulo registre las suyas | M | H-001-1 |
| H-008-2 Como sistema, quiero plantillas predeterminadas es/en con renderizado seguro y variables de lista blanca, para enviar correos correctos | M | H-008-1 |
| H-008-3 Como organizador, quiero ver el catálogo con su estado en el panel, para saber qué envía el sistema (Debería) | M | H-008-1, H-001-3 |
| H-008-4 Como organizador, quiero personalizar asunto, cuerpo, remitente y responder-a de mi organización con vista previa y restaurar la predeterminada, para adaptar la voz (Debería) | M | H-008-2, H-008-3 |
| H-008-5 Como organizador, quiero activar o desactivar las notificaciones no críticas, para controlar el ruido (Debería) | S | H-008-3 |
| H-008-6 Como sistema, quiero enviar los correos por cola con Resend y un adaptador intercambiable a SES, con reintentos e idempotencia, para entregarlos de forma fiable | M | H-008-1, H-008-2 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel; el entorno local prevé Mailpit para ver los correos sin enviarlos (`docs/entorno-local.md` §6). El glosario define `MailTemplate` solo «por organización»: este plan lo amplía (predeterminada global del sistema, organización y evento) y exige añadir `NotificationType` al glosario (cambio propuesto en el plan maestro). Dependen de este plan los planes 001 (restablecer contraseña), 003 (alta de equipo) y 006 (acuse y enlace de acceso): por eso las fases F1 a F3 (catálogo, plantillas y envío) deben cerrarse **antes** que H-001-4 y H-003-3; H-008-3 a H-008-5 pueden ir después. Las acciones A2 (DNS de Resend) y el spike S-2 (entregabilidad) son ruta crítica.

## 3. Decisiones

- **DA-1 · ADR-0009** — se propone un ADR corto, **«Correo transaccional y catálogo de notificaciones»**, con: Resend en R0 y SES como proveedor de crecimiento detrás de una interfaz; el catálogo en código como única fuente; y la regla «toda notificación nueva se registra con su predeterminada y su prueba» (adición a la definición de terminado de `AGENTS.md`). No se aplica sin aprobación.
- **DA-2 · Formato del cuerpo** — se recomienda **Markdown con HTML de entrada deshabilitado** y enlaces seguros (CommonMark, ya incluido en Laravel), sin editor HTML libre. Alternativa descartada: Blade o HTML con sanitizador, que amplía la superficie de ataque.
- **DA-3 · Variables y orden de render** — sintaxis `{{ variable }}` contra una **lista blanca por notificación**; una variable desconocida se rechaza al guardar y nunca se evalúa como código. El orden es obligatorio: **primero** se renderiza el Markdown de la plantilla con las variables convertidas en marcadores opacos, y **después** se sustituyen por los valores ya escapados. Así un valor con `[x](https://evil)`, HTML o sintaxis Markdown no puede generar enlaces, imágenes ni etiquetas.
- **DA-4 · Dónde viven las predeterminadas** — en **archivos de `lang/` (es, en)** versionados con el código, cargadas en el catálogo; la plantilla del sistema no se edita desde la interfaz.
- **DA-5 · Modelo de personalización** — una sola tabla `mail_templates` con `event_id` nullable **desde R0** (nivel organización con `event_id` nulo; nivel evento con valor). El resolvedor ya consulta evento → organización → sistema; la pantalla para editar el nivel evento llega en el plan 013. Dos índices únicos parciales garantizan una sola plantilla por nivel.
- **DA-6 · Proveedor en R0** — Resend, con adaptador propio detrás de la interfaz `TransactionalMailer`. Se necesita un cliente de Resend: es una **dependencia nueva**, aprobada por Antonio el 2026-11-11 con el ADR-0009 (acción 4 de `AGENTS.md`). Límites y precio `[PENDIENTE: verificar vigentes]`.
- **DA-7 · Remitente** — el correo remitente debe pertenecer al dominio verificado (A2); la organización solo cambia el nombre visible y el responder-a, no el dominio, en R0.
- **DA-8 · Reintentos y límites por destinatario** — **5 intentos** con espera creciente. Cada descriptor del catálogo puede declarar `recipient_limit` (envíos por destinatario y hora): `submission.received` y `submission.access_link` con 5; el resto sin límite. Un envío por encima del límite no se manda y se registra como `throttled` (sin el correo en claro).
- **DA-9 · Idempotencia** — la clave es `notification_key` + el identificador del hecho de negocio que la dispara (por ejemplo, el `id` de la propuesta); en `submission.access_link` se añade un `nonce` por solicitud, para que un segundo enlace pedido sí se envíe.

## 4. Contrato

- **Catálogo:** `App\Support\Notifications\NotificationCatalog` con un descriptor por notificación: `key`, `name`, `description`, `trigger`, `recipient`, `variables` (lista blanca), `locales`, `critical`, `recipient_limit`. Claves de R0: `auth.password_reset`, `team.set_password`, `team.membership_added`, `submission.received`, `submission.access_link`, `submission.new_for_organizers` y, como «Debería», `submission.updated` y `submission.withdrawn`. Las críticas son `auth.password_reset`, `team.set_password`, `team.membership_added`, `submission.received` y `submission.access_link`.
- **`team.membership_added`** (crítica; la usa el plan 003): destinatario, un usuario existente al que se añade a otra organización; variables `member_name`, `organization_name`, `role_name`, `login_url`.
- **Resolución de plantilla:** `ResolveMailTemplate::handle($key, $locale, $organization, $event = null)` devuelve evento → organización → sistema.
- **Renderizado:** `RenderMailTemplate` (Markdown sin HTML primero, valores escapados después; asunto y variables de una sola línea).
- **Envío:** interfaz `TransactionalMailer` con adaptador `ResendMailer` (y la plantilla de adaptador para SES en R1); Action `SendNotification::handle($key, $recipient, $context)` que **es la única que fija el destinatario**; Job `SendTransactionalMail` en cola con clave de idempotencia. Ninguna plantilla ni variable decide a quién se envía.
- **Contrato con el plan 006:** `submission.received` y `submission.access_link` usan el límite por destinatario de DA-8; el plan 006 mantiene además su propio límite por IP.
- **Form Requests:** `UpdateMailTemplateRequest` (asunto, cuerpo, nombre del remitente, responder-a; valida variables de la lista blanca, longitudes y ausencia de saltos de línea en asunto, nombre y responder-a) y `PreviewMailTemplateRequest`.
- **Panel (Filament):** página de catálogo y edición de plantillas, sin rutas JSON públicas. Los webhooks de entrega son del plan 013 (y se documentarán en `docs/api/endpoints.md` entonces).
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* personalizar correos por organización (y por evento en R1) y evitar envíos duplicados; ámbito organización; ciclo de vida: mientras exista la organización.
  2. *Inspección:* sin esquema previo; `organizations` del plan 001 y `events` del plan 003.
  3. *Modelo:* `mail_templates(id uuid, organization_id uuid NOT NULL FK, event_id uuid NULL FK, notification_key string, locale string, subject text, body text, from_name string NULL, reply_to string NULL, is_enabled boolean default true, created_by, updated_by, timestamps)`. Índices únicos parciales: `unique(organization_id, notification_key, locale) WHERE event_id IS NULL` y `unique(organization_id, event_id, notification_key, locale) WHERE event_id IS NOT NULL`. La FK compuesta `(event_id, organization_id)` hacia `events` requiere `unique(id, organization_id)` en `events` (plan 003). Además `mail_dispatches(id uuid, organization_id uuid NOT NULL FK, notification_key string, idempotency_key string, status string CHECK(queued, sent, failed, throttled), attempts int, sent_at NULL, timestamps)` con único `(organization_id, idempotency_key)`; no guarda el destinatario (el registro de envíos del plan 013 lo extenderá con retención definida). La **plantilla del sistema no es una tabla**: vive en `lang/` y se declara como global en `TenantModels::GLOBAL_MODELS` si se modela con clase.
  4. *Integridad:* `CHECK` de `locale` en (es, en, pt) desde R0; `notification_key` validada contra el catálogo en la aplicación; `SoftDeletes` no aplica a `mail_dispatches`.
  5. *Cambio:* migración nueva; el plan 013 no necesita alterar índices ni el `CHECK`.
  6. *Alternativa descartada:* una tabla para el sistema y otra para la organización, que duplica la resolución; y un `UNIQUE` simple con `event_id` nullable, que en PostgreSQL permite duplicados con `NULL`.
  7. *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador de organización: ver (`mail-templates.view`), editar, restaurar y desactivar no críticas (`mail-templates.manage`). Miembro con `mail-templates.view`: solo ver. Nadie edita la predeterminada del sistema. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `MailTemplatePolicy` (`viewAny`, `update`, `restore`, `toggle`); una prueba por rol y acción, permitido y denegado, en `tests/Feature/Mail/MailTemplatePolicyTest.php`. |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `MailTemplate` y `MailDispatch` usan `BelongsToOrganization`. El catálogo y las predeterminadas son código/global declarado. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0. |
| ¿Datos personales nuevos? | Los correos incluyen nombre y correo del destinatario; no se guardan en estas tablas (`mail_dispatches` no guarda el destinatario). El registro de envíos (plan 013) los conservará con retención definida. Los logs no contienen datos personales. Se actualiza la lista de subencargados en `docs/privacidad.md` con el proveedor de correo. |
| ¿Se suben archivos? | No en R0 (el logotipo es del plan 013). |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Sí, todos los de §7.5 del plan maestro más `team.membership_added`, **por cola**, con reintentos, idempotencia y límite por destinatario. |
| ¿Qué se registra en la auditoría? | Cambios de plantilla (quién, qué clave, cuándo, sin el cuerpo completo si contiene datos), restauraciones y activación o desactivación. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spike S-2: dominio verificado en Resend, envío real de prueba; ADR-0009 propuesto; interfaz `TransactionalMailer` con `Mail::fake()` | S |
| F1 | H-008-1: catálogo y guardiana | M |
| F2 | H-008-2: predeterminadas es/en, renderizado seguro, variables | M |
| F3 | H-008-6: envío por cola con Resend, reintentos, idempotencia y límite por destinatario | M |
| F4 | H-008-3 (Debería): pantalla del catálogo | M |
| F5 | H-008-4 y H-008-5 (Debería): personalización por organización, vista previa, restaurar y desactivar | M |

## 7. Criterios de aceptación

### H-008-1 · Catálogo en código

- **CA-008-1:** *Dado* el catálogo, *cuando* se consulta, *entonces* contiene las claves de R0 (incluida `team.membership_added`) con su nombre, descripción, disparador, destinatario, variables, idiomas, si es crítica y su límite por destinatario.
- **CA-008-2:** *Dado* una clase de notificación enviable que no está registrada en el catálogo, *cuando* corren las guardianas, *entonces* la prueba falla indicando la clase.
- **CA-008-3:** *Dado* una clave del catálogo sin plantilla predeterminada en español o en inglés, *cuando* corren las guardianas, *entonces* la prueba falla indicando la clave y el idioma faltante.

### H-008-2 · Plantillas y renderizado seguro

- **CA-008-4:** *Dado* `submission.received` en español con sus variables, *cuando* se renderiza, *entonces* el asunto y el cuerpo contienen los valores sustituidos y ninguna llave `{{ }}` sin resolver.
- **CA-008-5:** *Dado* un cuerpo con una variable fuera de la lista blanca (`{{ app_key }}`), *cuando* se guarda o se renderiza, *entonces* se rechaza con un error de validación y el valor no se evalúa nunca.
- **CA-008-6:** *Dado* un cuerpo con `<script>`, HTML crudo, sintaxis Blade (`@php`, `{!! !!}`) o PHP, *cuando* se renderiza, *entonces* se muestra como texto escapado o se elimina, y no se ejecuta nada.
- **CA-008-7:** *Dado* un valor de variable con HTML (por ejemplo, el título de una propuesta con `<b>`), *cuando* se renderiza, *entonces* sale escapado.
- **CA-008-8:** *Dado* un enlace `javascript:` o con protocolo no permitido en el cuerpo, *cuando* se renderiza, *entonces* el enlace se neutraliza.
- **CA-008-9:** *Dado* un valor de variable `[Verifica tu cuenta](https://evil.example)` (por ejemplo, el título de una propuesta), *cuando* se renderiza `submission.new_for_organizers`, *entonces* el correo contiene ese texto literal escapado y no genera ningún enlace, imagen ni etiqueta HTML, porque los valores se sustituyen después de renderizar el Markdown.
- **CA-008-10:** *Dado* un valor de variable con saltos de línea o `\r\nBcc: otro@example.com`, *cuando* se renderiza el asunto o un campo de cabecera, *entonces* los saltos de línea se eliminan y no se añade ninguna cabecera.
- **CA-008-11:** *Dado* cualquier plantilla (del sistema o de la organización), *cuando* se prepara un envío, *entonces* el destinatario lo fija únicamente `SendNotification` con datos del sistema y ningún campo de la plantilla ni valor de variable puede cambiarlo ni añadir copias.

### H-008-3 · Pantalla del catálogo (Debería)

- **CA-008-12:** *Dado* un organizador con permiso `mail-templates.view`, *cuando* abre el catálogo, *entonces* ve todas las notificaciones con su estado (predeterminada, personalizada, desactivada) y si son críticas.
- **CA-008-13:** *Dado* un usuario de la misma organización sin el permiso, *cuando* abre el catálogo o una plantilla, *entonces* recibe 403; y *dado* una plantilla de otra organización, *cuando* se pide su identificador, *entonces* la respuesta es 404.
- **CA-008-14:** *Dado* la pantalla del catálogo, *cuando* se abre a ~375 px y ~1280 px, *entonces* es legible y navegable por teclado, y sin errores en la consola.
  Manual: abrir la página de catálogo del panel en `staging` a esos dos anchos con la consola abierta.

### H-008-4 · Personalización por organización (Debería)

- **CA-008-15:** *Dado* un administrador de la organización A con `mail-templates.manage`, *cuando* guarda un asunto y cuerpo propios para `submission.received` en es, *entonces* los correos de A usan esa plantilla y los de B siguen con la predeterminada.
- **CA-008-16:** *Dado* una plantilla personalizada, *cuando* el administrador pide la vista previa, *entonces* ve el resultado con datos de ejemplo y sin enviar ningún correo.
- **CA-008-17:** *Dado* una plantilla personalizada, *cuando* se restaura la predeterminada, *entonces* se elimina la personalización y el siguiente envío usa la predeterminada; la acción queda auditada.
- **CA-008-18:** *Dado* un responder-a o un nombre de remitente con saltos de línea o un correo inválido, o un asunto con saltos de línea, *cuando* se guarda, *entonces* se rechaza con un error de validación (sin inyección de cabeceras).
- **CA-008-19:** *Dado* dos filas de `mail_templates` de organización con la misma clave e idioma y `event_id` nulo, o dos de evento con la misma clave, idioma y evento, *cuando* se intenta guardar la segunda, *entonces* la base la rechaza por el índice único parcial; y una de organización más una de evento con la misma clave sí conviven.
- **CA-008-20:** *Dado* una plantilla de la organización y otra del evento para la misma clave, *cuando* `ResolveMailTemplate` recibe ese evento, *entonces* devuelve la del evento; sin evento, la de la organización; sin ninguna, la predeterminada del sistema.

### H-008-5 · Activar o desactivar no críticas (Debería)

- **CA-008-21:** *Dado* una notificación no crítica (`submission.new_for_organizers`), *cuando* el administrador la desactiva, *entonces* no se envía y la decisión queda auditada.
- **CA-008-22:** *Dado* una notificación crítica (`auth.password_reset`), *cuando* se intenta desactivar (por la interfaz o directamente), *entonces* se rechaza y sigue activa.

### H-008-6 · Envío por cola con proveedor intercambiable

- **CA-008-23:** *Dado* una notificación disparada, *cuando* se encola, *entonces* el envío ocurre en un job y el proceso web no espera al proveedor.
- **CA-008-24:** *Dado* un fallo transitorio del proveedor, *cuando* el job falla, *entonces* se reintenta con espera creciente hasta 5 intentos y, agotados, queda como fallido visible (no se descarta en silencio).
- **CA-008-25:** *Dado* el mismo evento de negocio procesado dos veces (reintento de cola), *cuando* se envía, *entonces* el destinatario recibe **un solo** correo, porque la clave `notification_key` + id del hecho ya existe en `mail_dispatches`.
- **CA-008-26:** *Dado* un autor que pide `submission.access_link` dos veces, *cuando* se envían ambas solicitudes, *entonces* recibe dos correos distintos (cada una con su `nonce`), sin duplicar ninguno de ellos ante un reintento de cola.
- **CA-008-27:** *Dado* dos adaptadores (Resend y uno de prueba) detrás de `TransactionalMailer`, *cuando* se cambia la configuración, *entonces* los módulos y las plantillas funcionan sin cambios de código.
- **CA-008-28:** *Dado* 5 correos `submission.received` ya enviados a la misma dirección en la última hora, *cuando* se pide uno más, *entonces* no se envía, `mail_dispatches` lo marca `throttled` y no se registra el correo en claro; los de otra dirección no se ven afectados.
- **CA-008-29:** *Dado* el dominio verificado en Resend, *cuando* se envía un correo real a Gmail y a Outlook, *entonces* llega a la bandeja principal con SPF, DKIM y DMARC en *pass* (criterio G4).
  Manual: en `staging` con dominio real, enviar `submission.received` a una cuenta de Gmail y otra de Outlook, abrir «ver original» y comprobar los tres resultados.
- **CA-008-30:** *Dado* un usuario del entorno local con el flujo de restablecimiento de contraseña (H-001-4), *cuando* pide restablecerla, *entonces* el correo `auth.password_reset` llega a Mailpit con el enlace correcto y el enlace permite fijar la contraseña de punta a punta.
  Manual: en local, pedir el restablecimiento, abrir el mensaje en http://localhost:8025 y completar el cambio de contraseña. Se ejecuta cuando H-001-4 está disponible: es una comprobación del cierre conjunto de H-008-6 y H-001-4, no una dependencia de código de H-008-6.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Entregabilidad insuficiente el día del lanzamiento | A2 hoy, spike S-2, prueba G4 y SES preparado como alternativa |
| Plantilla editable como vector de inyección | Markdown sin HTML, lista blanca de variables, render antes de sustituir, sin Blade ni PHP, pruebas CA-008-5 a 008-11 |
| Phishing con valores controlados por un autor anónimo | Sustitución posterior al render con valores escapados (CA-008-9) |
| Plan gratuito del proveedor insuficiente para los acuses | `[PENDIENTE: verificar límites]` antes de H2; pasar a un plan de pago si hace falta |
| Una notificación nueva olvida registrarse | Guardiana CA-008-2 y regla de proceso (ADR-0009) |
| Se usa el formulario para mandar correos a terceros | Límite por destinatario (DA-8) y límite por IP del plan 006 |
| La edición por organización no cabe en R0 | Está marcada «Debería»; se usan las predeterminadas |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 (H-008-1) | 0bdbbbd | 2026-11-12 | 7 pruebas del catalogo y paridad es/en en verde (24 pruebas, 159 aserciones); Pint OK; Larastan sin errores de codigo (2 avisos de patrones ignorados por analizar un subconjunto). CA-008-1 a 3 cubiertos. Desviaciones: se anade `auth.new_device_login` al catalogo (la usa el plan 001 y la pide el plan maestro §7.5) y `team.membership_added` queda critica, como dice este plan. Las plantillas predeterminadas es/en de las 9 claves ya existen (adelanto de F2). No se ejecuto `composer check` completo. |
| F2 (H-008-2) | e7b8dd9 | 2026-11-12 | 25 pruebas de notificaciones en verde (189 aserciones); Pint OK; Larastan sin errores de codigo (2 avisos de patrones ignorados por analizar un subconjunto). CA-008-4 a 10 cubiertos. Desviaciones: CA-008-11 (destinatario fijado solo por `SendNotification`) se prueba en F3, donde nace esa Action; las URLs de las plantillas son enlaces Markdown y toda variable `*_url` debe ser http(s). `validate()` queda listo para el guardado de H-008-4. No se ejecuto `composer check` completo. |
| F3 (H-008-6) | e0d7de7 | 2026-11-12 | 32 pruebas nuevas en verde (CA-008-11 y 23 a 28), mas aislamiento entre organizaciones y paridad es/en (192 en la corrida conjunta, ejecutadas contra testing_b); Pint OK en 26 archivos; Larastan sin errores de codigo (1 aviso de patron ignorado por analizar un subconjunto); check:criteria 0 errores. Desviaciones: (1) `mail_dispatches` anade `recipient_hash` (HMAC con la clave de la app), que la propuesta de datos no tenia, porque CA-008-28 exige contar envios por direccion sin guardarla en claro; (2) `auth.password_reset` y `auth.new_device_login` no pasan por `SendNotification` porque no tienen organizacion: DT-14, se migran en H-001-4. Pendiente manual: CA-008-29 (SPF/DKIM/DMARC con envio real a Gmail y Outlook, necesita el dominio verificado) y CA-008-30 (Mailpit de punta a punta, con H-001-4). Se instalo `resend/resend-php` ^1.18 (aprobado en el ADR-0009). No se ejecuto `composer check` completo ni el navegador. |
