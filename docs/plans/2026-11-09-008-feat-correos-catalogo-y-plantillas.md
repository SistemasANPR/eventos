# feat: correos, catálogo de notificaciones y plantillas

> Plan de la épica E-08 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. El nivel evento, el logotipo y colores, pt, versiones, envío de prueba y registro de envíos son del plan 013 (R1).

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** RC-6 (edición de plantillas por organización como «Debería»)

## 1. Objetivo y alcance

Un módulo **transversal** donde vive todo correo que dispara el sistema. Cada módulo registra sus notificaciones en un catálogo central en código; cada notificación tiene una plantilla predeterminada del sistema en español e inglés; la organización puede (si cabe en R0) adaptar asunto, cuerpo y remitente. El envío es por cola, con reintentos e idempotencia, y el proveedor (Resend primero, SES después) es intercambiable sin tocar los módulos. El cpanel de origen no enviaba ningún correo desde el panel.

**Dentro:** catálogo en código y guardiana; plantillas predeterminadas es/en; renderizado seguro con variables de lista blanca; pantalla del catálogo con estado; personalización por organización con vista previa y restaurar (Debería); activar o desactivar no críticas (Debería); envío por cola con adaptador Resend e interfaz para SES; los correos mínimos de R0 (§7.5 del plan maestro).

**Fuera:** plantilla por evento, logotipo, colores, pie y firma, idioma pt, historial de versiones, envío de prueba, registro de envíos con estado de entrega y adaptador SES (plan 013, R1); campañas masivas (E-29); correos de evaluación y resultados (planes 011 y 012, que registran sus claves en este catálogo).

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-008-1 Como equipo, quiero un catálogo en código de notificaciones, para que cada módulo registre las suyas | M | H-001-1 |
| H-008-2 Como sistema, quiero plantillas predeterminadas es/en con renderizado seguro y variables de lista blanca, para enviar correos correctos | M | H-008-1 |
| H-008-3 Como organizador, quiero ver el catálogo con su estado en el panel, para saber qué envía el sistema | M | H-008-1, H-001-3 |
| H-008-4 Como organizador, quiero personalizar asunto, cuerpo, remitente y responder-a de mi organización con vista previa y restaurar la predeterminada, para adaptar la voz (Debería) | M | H-008-2, H-008-3 |
| H-008-5 Como organizador, quiero activar o desactivar las notificaciones no críticas, para controlar el ruido (Debería) | S | H-008-3 |
| H-008-6 Como sistema, quiero enviar los correos por cola con Resend y un adaptador intercambiable a SES, con reintentos e idempotencia, para entregarlos de forma fiable | M | H-008-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel; el entorno local prevé Mailpit para ver los correos sin enviarlos (`docs/entorno-local.md` §6). El glosario define `MailTemplate` solo «por organización»: este plan lo amplía (predeterminada global del sistema, organización y, en R1, evento) y exige añadir `NotificationType` al glosario (cambio propuesto en el plan maestro). Dependen de este plan los planes 001 (restablecer contraseña), 003 (alta de equipo) y 006 (acuse y enlace de acceso). Las acciones A2 (DNS de Resend) y el spike S-2 (entregabilidad) son ruta crítica.

## 3. Decisiones

- **DA-1 · ADR-0009** — se propone un ADR corto, **«Correo transaccional y catálogo de notificaciones»**, con: Resend en R0 y SES como proveedor de crecimiento detrás de una interfaz; el catálogo en código como única fuente; y la regla «toda notificación nueva se registra con su predeterminada y su prueba» (adición a la definición de terminado de `AGENTS.md`). No se aplica sin aprobación.
- **DA-2 · Formato del cuerpo** — se recomienda **Markdown con HTML de entrada deshabilitado** y enlaces seguros (CommonMark, ya incluido en Laravel), sin editor HTML libre. Alternativa descartada: Blade o HTML con sanitizador, que amplía la superficie de ataque.
- **DA-3 · Variables** — se recomienda sintaxis `{{ variable }}` reemplazada por una función propia contra una **lista blanca por notificación**; una variable desconocida se rechaza al guardar y nunca se evalúa como código.
- **DA-4 · Dónde viven las predeterminadas** — se recomienda en **archivos de `lang/` (es, en)** versionados con el código, cargadas en el catálogo; la plantilla del sistema no se edita desde la interfaz.
- **DA-5 · Modelo de personalización de R0** — se recomienda una sola tabla `mail_templates` con ámbito organización (nivel `organization`); el nivel `event` llega en R1 (plan 013) sin romper el contrato de resolución.
- **DA-6 · Proveedor en R0** — Resend, con adaptador propio detrás de la interfaz `TransactionalMailer`. Se necesita un cliente de Resend: es una **dependencia nueva** que exige tu permiso (acción 4 de `AGENTS.md`) `[PENDIENTE: aprobación]`. Límites y precio `[PENDIENTE: verificar vigentes]`.
- **DA-7 · Remitente** — el correo remitente debe pertenecer al dominio verificado (A2); la organización solo cambia el nombre visible y el responder-a, no el dominio, en R0.

## 4. Contrato

- **Catálogo:** `App\Support\Notifications\NotificationCatalog` con un descriptor por notificación: `key`, `name`, `description`, `trigger`, `recipient`, `variables` (lista blanca), `locales`, `critical`. Claves de R0: `auth.password_reset`, `team.set_password`, `submission.received`, `submission.access_link`, `submission.new_for_organizers` y, como «Debería», `submission.updated` y `submission.withdrawn`.
- **Resolución de plantilla:** `ResolveMailTemplate::handle($key, $locale, $organization, $event = null)` devuelve evento → organización → sistema (el nivel evento queda inerte hasta R1).
- **Renderizado:** `RenderMailTemplate` (Markdown sin HTML + variables permitidas).
- **Envío:** interfaz `TransactionalMailer` con adaptador `ResendMailer` (y la plantilla de adaptador para SES en R1); `SendTransactionalMail` como Job en cola con clave de idempotencia.
- **Form Requests:** `UpdateMailTemplateRequest` (asunto, cuerpo, nombre del remitente, responder-a; valida variables de la lista blanca y longitudes) y `PreviewMailTemplateRequest`.
- **Panel (Filament):** `MailTemplateResource` o página de catálogo, sin rutas JSON públicas. Los webhooks de entrega son del plan 013 (y se documentarán en `docs/api/endpoints.md` entonces).
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* personalizar correos por organización; ámbito organización; ciclo de vida: mientras exista la organización.
  2. *Inspección:* sin esquema previo; `organizations` del plan 001.
  3. *Modelo:* `mail_templates(id uuid, organization_id uuid NOT NULL FK, notification_key string, locale string, subject text, body text, from_name string NULL, reply_to string NULL, is_enabled boolean default true, created_by, updated_by, timestamps)` con único `(organization_id, notification_key, locale)`. La **plantilla del sistema no es una tabla**: vive en `lang/` y se declara como global en `TenantModels::GLOBAL_MODELS` si se modela con clase.
  4. *Integridad:* `CHECK` de `locale` en (es, en) en R0; `notification_key` validada contra el catálogo en la aplicación.
  5. *Cambio:* migración nueva.
  6. *Alternativa descartada:* una tabla para el sistema y otra para la organización, que duplica la resolución.
  7. *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador de organización: ver, editar, restaurar, desactivar no críticas. Miembro con permiso `mail-templates.view`: solo ver. Nadie edita la predeterminada del sistema. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `MailTemplatePolicy` (`viewAny`, `update`, `restore`, `toggle`); una prueba por rol y acción, permitido y denegado, en `tests/Feature/Mail/MailTemplatePolicyTest.php`. |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `MailTemplate` usa `BelongsToOrganization`. El catálogo y las predeterminadas son código/global declarado. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0. |
| ¿Datos personales nuevos? | Los correos incluyen nombre y correo del destinatario; no se guardan en esta tabla. El registro de envíos (plan 013) los conservará con retención definida. Los logs no contienen datos personales. Se actualiza la lista de subencargados en `docs/privacidad.md` con el proveedor de correo. |
| ¿Se suben archivos? | No en R0 (el logotipo es del plan 013). |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | Sí, todos los de §7.5 del plan maestro, **por cola**, con reintentos e idempotencia. |
| ¿Qué se registra en la auditoría? | Cambios de plantilla (quién, qué clave, cuándo, sin el cuerpo completo si contiene datos), restauraciones y activación o desactivación. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spike S-2: dominio verificado en Resend, envío real de prueba; ADR-0009 propuesto; interfaz `TransactionalMailer` con `Mail::fake()` | S |
| F1 | H-008-1: catálogo y guardiana | M |
| F2 | H-008-2: predeterminadas es/en, renderizado seguro, variables | M |
| F3 | H-008-6: envío por cola con Resend, reintentos e idempotencia | M |
| F4 | H-008-3: pantalla del catálogo | M |
| F5 | H-008-4 y H-008-5 (Debería): personalización por organización, vista previa, restaurar y desactivar | M |

## 7. Criterios de aceptación

### H-008-1 · Catálogo en código

- **CA-008-1:** *Dado* el catálogo, *cuando* se consulta, *entonces* contiene las claves de R0 con su nombre, descripción, disparador, destinatario, variables, idiomas y si es crítica.
- **CA-008-2:** *Dado* una clase de notificación enviable que no está registrada en el catálogo, *cuando* corren las guardianas, *entonces* la prueba falla indicando la clase.
- **CA-008-3:** *Dado* una clave del catálogo sin plantilla predeterminada en español o en inglés, *cuando* corren las guardianas, *entonces* la prueba falla indicando la clave y el idioma faltante.

### H-008-2 · Plantillas y renderizado seguro

- **CA-008-4:** *Dado* `submission.received` en español con sus variables, *cuando* se renderiza, *entonces* el asunto y el cuerpo contienen los valores sustituidos y ninguna llave `{{ }}` sin resolver.
- **CA-008-5:** *Dado* un cuerpo con una variable fuera de la lista blanca (`{{ app_key }}`), *cuando* se guarda o se renderiza, *entonces* se rechaza con un error de validación y el valor no se evalúa nunca.
- **CA-008-6:** *Dado* un cuerpo con `<script>`, HTML crudo, sintaxis Blade (`@php`, `{!! !!}`) o PHP, *cuando* se renderiza, *entonces* se muestra como texto escapado o se elimina, y no se ejecuta nada.
- **CA-008-7:** *Dado* un valor de variable con HTML (por ejemplo, el título de una propuesta con `<b>`), *cuando* se renderiza, *entonces* sale escapado.
- **CA-008-8:** *Dado* un enlace `javascript:` o con protocolo no permitido en el cuerpo, *cuando* se renderiza, *entonces* el enlace se neutraliza.

### H-008-3 · Pantalla del catálogo

- **CA-008-9:** *Dado* un organizador con permiso de lectura, *cuando* abre el catálogo, *entonces* ve todas las notificaciones con su estado (predeterminada, personalizada, desactivada) y si son críticas.
- **CA-008-10:** *Dado* un usuario sin el permiso o de otra organización, *cuando* abre el catálogo o una plantilla, *entonces* recibe 403 o 404 según corresponda y no ve datos ajenos.
- **CA-008-11:** *Dado* la pantalla del catálogo, *cuando* se abre a ~375 px y ~1280 px, *entonces* es legible y navegable por teclado, y sin errores en la consola.
  Manual: abrir `/admin/mail-templates` en `staging` a esos dos anchos con la consola abierta.

### H-008-4 · Personalización por organización (Debería)

- **CA-008-12:** *Dado* un administrador de la organización A, *cuando* guarda un asunto y cuerpo propios para `submission.received` en es, *entonces* los correos de A usan esa plantilla y los de B siguen con la predeterminada.
- **CA-008-13:** *Dado* una plantilla personalizada, *cuando* el administrador pide la vista previa, *entonces* ve el resultado con datos de ejemplo y sin enviar ningún correo.
- **CA-008-14:** *Dado* una plantilla personalizada, *cuando* se restaura la predeterminada, *entonces* se elimina la personalización y el siguiente envío usa la predeterminada; la acción queda auditada.
- **CA-008-15:** *Dado* un responder-a o un nombre de remitente con saltos de línea o un correo inválido, *cuando* se guarda, *entonces* se rechaza (sin inyección de cabeceras).

### H-008-5 · Activar o desactivar no críticas (Debería)

- **CA-008-16:** *Dado* una notificación no crítica (`submission.new_for_organizers`), *cuando* el administrador la desactiva, *entonces* no se envía y la decisión queda auditada.
- **CA-008-17:** *Dado* una notificación crítica (`auth.password_reset`), *cuando* se intenta desactivar (por la interfaz o directamente), *entonces* se rechaza y sigue activa.

### H-008-6 · Envío por cola con proveedor intercambiable

- **CA-008-18:** *Dado* una notificación disparada, *cuando* se encola, *entonces* el envío ocurre en un job y el proceso web no espera al proveedor.
- **CA-008-19:** *Dado* un fallo transitorio del proveedor, *cuando* el job falla, *entonces* se reintenta con espera creciente hasta un máximo `[PENDIENTE: número de reintentos]` y, agotados, queda como fallido visible (no se descarta en silencio).
- **CA-008-20:** *Dado* el mismo evento de negocio procesado dos veces (reintento de cola), *cuando* se envía, *entonces* el destinatario recibe **un solo** correo (idempotencia por clave).
- **CA-008-21:** *Dado* dos adaptadores (Resend y uno de prueba) detrás de `TransactionalMailer`, *cuando* se cambia la configuración, *entonces* los módulos y las plantillas funcionan sin cambios de código.
- **CA-008-22:** *Dado* el dominio verificado en Resend, *cuando* se envía un correo real a Gmail y a Outlook, *entonces* llega a la bandeja principal con SPF, DKIM y DMARC en *pass* (criterio G4).
  Manual: en `staging` con dominio real, enviar `submission.received` a una cuenta de Gmail y otra de Outlook, abrir «ver original» y comprobar los tres resultados.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Entregabilidad insuficiente el día del lanzamiento | A2 hoy, spike S-2, prueba G4 y SES preparado como alternativa |
| Plantilla editable como vector de inyección | Markdown sin HTML, lista blanca de variables, sin Blade ni PHP, pruebas CA-008-5 a 008-8 |
| Plan gratuito del proveedor insuficiente para los acuses | `[PENDIENTE: verificar límites]` antes de H2; pasar a un plan de pago si hace falta |
| Una notificación nueva olvida registrarse | Guardiana CA-008-2 y regla de proceso (ADR-0009) |
| La edición por organización no cabe en R0 | Está marcada «Debería»; se usan las predeterminadas |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
