# feat: correos avanzados (plantillas por evento, marca, versiones y registro de envíos)

> Plan de la épica E-13 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 013: todos sus criterios son `CA-013-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** RC-4 en parte (logotipo y colores solo en correos; la marca blanca completa queda en R5)

## 1. Objetivo y alcance

Completar el módulo de correos del plan 008: que cada evento adapte los correos a su identidad y a su idioma, con historial, prueba de envío y trazabilidad de lo enviado a cada persona, y sumar Amazon SES como proveedor alterno. Resolución de plantilla: **evento → organización → predeterminada del sistema**.

**Dentro:** plantilla por evento que sobrescribe a la organización; logotipo, colores, pie y firma validados; textos por idioma (es, en, pt); historial de versiones y restaurar; envío de prueba; registro de envíos con estado de entrega (webhooks con firma); adaptador de SES.

**Fuera:** campañas masivas y segmentación (R4, E-29); marca blanca completa del sitio público (R5); cualquier ejecución de código escrito por el usuario (nunca).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-013-1 | Como organizador, quiero que la plantilla del evento sobrescriba la de la organización, para adaptar cada evento | M | H-008-4 |
| H-013-2 | Como organizador, quiero logotipo, colores, pie y firma, para que el correo lleve mi marca | M | H-013-1 |
| H-013-3 | Como organizador, quiero textos por idioma (es, en, pt), para comunicarme en el idioma del autor | S | H-013-1 |
| H-013-4 | Como organizador, quiero historial de versiones y restaurar una anterior, para deshacer errores | M | H-013-1 |
| H-013-5 | Como organizador, quiero enviar un correo de prueba, para ver el resultado real | S | H-013-1 |
| H-013-6 | Como organizador, quiero un registro de envíos con estado de entrega, para saber qué recibió cada persona | M | H-008-6 |
| H-013-7 | Como líder, quiero enviar también con SES, para no depender de un solo proveedor | S | H-008-6, A3 |

## 2. Punto de partida

Estado esperado al iniciar: catálogo en código, plantillas predeterminadas es/en, personalización por organización de asunto, cuerpo, remitente y responder-a (planes 008, si «Debería» entró), envío por cola con Resend (H-008-6). La solicitud de salida del *sandbox* de SES (acción A3 del plan maestro) se abre antes. `[PENDIENTE: estado de la aprobación de SES y cuotas iniciales vigentes]`.

## 3. Decisiones

### DA-1 · Formato del cuerpo — se recomienda **Markdown restringido, sin HTML de entrada**

El cuerpo se escribe en Markdown, se renderiza con CommonMark con HTML deshabilitado y se envuelve en una plantilla de marca fija del sistema (logotipo, colores, pie). Nunca Blade ni PHP del usuario. Alternativa descartada: editor HTML libre, con superficie de ataque mayor.

### DA-2 · Variables — se recomienda **lista blanca por clave de notificación, rechazada al guardar**

Una variable `{{no_listada}}` hace fallar la validación al guardar y nunca se evalúa. Las variables se sustituyen por valores escapados.

### DA-3 · Retención del registro de envíos — se recomienda **plazo fijo y anonimización del destinatario al vencer**

`[PENDIENTE: plazo de retención en días y quién lo aprueba]`; se refleja en `docs/privacidad.md`.

### DA-4 · Logotipo — se recomienda **PNG o JPG con tamaño máximo, sin SVG**

El SVG puede llevar scripts (`docs/seguridad.md` §5). Se guarda en el almacenamiento privado de la organización y se sirve al correo con una URL firmada de larga vigencia o incrustado `[PENDIENTE: URL firmada o imagen incrustada]`.

## 4. Contrato

- **Rutas web (Filament):** `MailTemplateResource` con nivel (organización o evento), idioma y versiones; acción «enviar prueba»; página de registro de envíos. **Webhook de entrega** del proveedor: propuesta de entrada en `docs/api/endpoints.md` antes de implementar (`POST /api/v1/webhooks/mail-delivery/{provider}`, firma verificada, sin usuario, procesado en cola, idempotente por identificador de evento del proveedor).
- **Form Requests:** `UpdateMailTemplateRequest` (variables en lista blanca, longitudes, idioma permitido), `UploadMailLogoRequest` (tipo MIME real, tamaño, dimensiones), `SendTestMailRequest`, `RestoreMailTemplateVersionRequest`.
- **Actions:** `ResolveMailTemplate` (evento → organización → sistema), `UpdateMailTemplate`, `RestoreMailTemplateVersion`, `SendTestMail`, `RecordMailDelivery`, `SesMailTransport` detrás de la interfaz de transporte del plan 008.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* personalización por evento, versiones y trazabilidad de envíos; dueño: módulo de correos.
  2. *Modelo:* `mail_templates` (clave de notificación, nivel `organization|event`, `event_id` nullable, idioma, asunto, cuerpo, remitente, responder-a, `active`); `mail_template_versions` (plantilla, número, contenido, autor, instante UTC; solo se añade); `mail_branding` (organización, evento nullable, logotipo, colores, pie, firma); `mail_deliveries` (clave, destinatario y su `contact_id`, evento, estado `queued|sent|delivered|bounced|complained|failed`, identificador del proveedor único, instante UTC). Ámbito: organización y evento. La predeterminada del sistema vive en código y semillas, con `MailTemplate` global declarado en `TenantModels::GLOBAL_MODELS`.
  3. *Integridad:* único por `(organization_id, notification_key, locale, level, event_id)`; único `(provider, provider_event_id)` en entregas; claves foráneas con el mismo `organization_id`.
  4. *Cambio:* tablas y columnas nuevas; las plantillas por organización existentes pasan a la tabla ampliada sin cambiar su contenido.
  5. *Alternativa descartada:* una plantilla por idioma en columnas separadas; contradice ADR-0005 (traducciones en un registro).
- **Variables de entorno nuevas:** credenciales y región de SES, secreto del webhook; todas en `.env.example` con valores ficticios y en `docs/operacion.md`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Administrador y coordinador de evento: editar plantillas y marca del evento; administrador de organización: plantillas y marca de la organización, ver el registro de envíos. Evaluadores y autores: ninguno |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `MailTemplatePolicy` y `MailDeliveryPolicy`; `tests/Feature/Mail/` con permitido y denegado por rol |
| ¿Modelos nuevos con datos de organización? | Sí (`mail_templates`, `mail_template_versions`, `mail_branding`, `mail_deliveries`) con `BelongsToOrganization`; la predeterminada es global declarada |
| ¿El acceso cruza eventos o depende de `event_assignments`? | La plantilla de un evento solo la edita quien tiene asignación a ese evento o rol de organización |
| ¿Datos personales nuevos? | Destinatario (correo y contacto) y estado de entrega: base contrato/interés legítimo; retención en DA-3; anonimización con la persona (plan 014); proveedor de correo listado como subencargado |
| ¿Se suben archivos? | Logotipo: PNG o JPG, tamaño máximo `[PENDIENTE: límite en KB]`, disco privado, nombre generado, validación por contenido |
| ¿Dinero, pagos o webhooks? | Webhook de entrega: firma verificada antes de procesar, 4xx si no coincide, cola e idempotencia; sin dinero |
| ¿Se envían correos? | Sí, todos por cola; el de prueba solo al correo del usuario que lo pide |
| ¿Qué se registra en la auditoría? | Cambios de plantilla y de marca, restauraciones, envíos de prueba y consulta del registro de envíos |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos y entrada del webhook aprobadas; pruebas de variables y de sanitización | S |
| F1 | Resolución evento → organización → sistema y plantillas por evento (H-013-1) | M |
| F2 | Marca, logotipo y textos por idioma (H-013-2, H-013-3) | M |
| F3 | Versiones, restaurar y envío de prueba (H-013-4, H-013-5) | M |
| F4 | Registro de envíos y webhooks (H-013-6) | M |
| F5 | Transporte SES (H-013-7) | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-013-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-013-1 · Plantilla por evento

- **CA-013-1:** *Dado* una plantilla de organización y otra del evento X para la misma clave e idioma, *cuando* se envía una notificación del evento X, *entonces* se usa la del evento; en otro evento de la misma organización se usa la de la organización.
- **CA-013-2:** *Dado* que no existe plantilla de evento ni de organización, *cuando* se envía, *entonces* se usa la predeterminada del sistema en el idioma pedido.
- **CA-013-3:** *Dado* una plantilla del evento de la organización A, *cuando* un usuario de B la pide por su identificador, *entonces* la respuesta es 404.
- **CA-013-4:** *Dado* una plantilla con una variable fuera de la lista blanca, *cuando* se guarda, *entonces* se rechaza con error de validación que nombra la variable.

### H-013-2 · Marca, logotipo, pie y firma

- **CA-013-5:** *Dado* un logotipo PNG válido dentro del límite, *cuando* se sube, *entonces* se guarda en disco privado de la organización con nombre generado y aparece en la vista previa.
- **CA-013-6:** *Dado* un archivo con extensión `.png` pero contenido SVG, PHP o HTML, *cuando* se sube, *entonces* se rechaza por validación del contenido real.
- **CA-013-7:** *Dado* un color con formato inválido o pie con HTML, *cuando* se guarda, *entonces* el color se rechaza y el pie se sanitiza sin conservar etiquetas.
- **CA-013-8:** *Dado* una notificación crítica, *cuando* se renderiza con marca, *entonces* el cuerpo se genera a partir de Markdown sin HTML de entrada y no se ejecuta ningún código del usuario.
  Manual: revisar el correo real en un cliente de escritorio y en un móvil de ~375 px, con modo oscuro.

### H-013-3 · Textos por idioma

- **CA-013-9:** *Dado* un evento con idiomas es, en y pt, *cuando* se envía una notificación a un autor cuyo idioma es pt, *entonces* se usa el texto en pt.
- **CA-013-10:** *Dado* un idioma sin texto propio, *cuando* se envía, *entonces* se usa el idioma base español y se registra el respaldo; nunca se envía un correo sin cuerpo.
- **CA-013-11:** *Dado* el catálogo, *cuando* corren las guardianas, *entonces* toda notificación tiene predeterminada en es, en y pt.

### H-013-4 · Versiones

- **CA-013-12:** *Dado* una plantilla editada tres veces, *cuando* se abre su historial, *entonces* aparecen tres versiones con autor e instante UTC, de solo lectura.
- **CA-013-13:** *Dado* una versión anterior, *cuando* el organizador la restaura, *entonces* se crea una versión nueva con ese contenido (el historial no se reescribe) y se audita.
- **CA-013-14:** *Dado* un organizador sin permiso, *cuando* intenta restaurar, *entonces* se deniega.

### H-013-5 · Envío de prueba

- **CA-013-15:** *Dado* una plantilla en edición, *cuando* el organizador pide una prueba, *entonces* se envía con datos de ejemplo solo al correo del usuario autenticado, con el asunto marcado como prueba.
- **CA-013-16:** *Dado* una petición de prueba con un destinatario distinto en el cuerpo de la solicitud, *cuando* se procesa, *entonces* se ignora el destinatario del cliente y se usa el del usuario autenticado.
- **CA-013-17:** *Dado* un uso repetido, *cuando* supera el límite de peticiones, *entonces* la respuesta es 429.

### H-013-6 · Registro de envíos

- **CA-013-18:** *Dado* un correo enviado, *cuando* el proveedor notifica la entrega, *entonces* el registro cambia a `delivered` y conserva el identificador del proveedor.
- **CA-013-19:** *Dado* un webhook con firma inválida o ausente, *cuando* llega, *entonces* se rechaza con 4xx y no se modifica ningún registro.
- **CA-013-20:** *Dado* un webhook repetido o desordenado con el mismo identificador de evento, *cuando* se procesa, *entonces* el estado final es correcto y no se duplican registros (idempotencia).
- **CA-013-21:** *Dado* un administrador de A, *cuando* abre el registro de envíos, *entonces* solo ve envíos de A; los destinatarios no aparecen en registros de aplicación.
- **CA-013-22:** *Dado* un rebote o queja, *cuando* se registra, *entonces* el contacto queda marcado como «no enviar» y los siguientes envíos no críticos a ese correo se omiten con aviso.

### H-013-7 · Adaptador de SES

- **CA-013-23:** *Dado* la configuración de proveedor `ses`, *cuando* se envía un correo del catálogo, *entonces* sale por SES sin cambiar el código del módulo que lo dispara.
- **CA-013-24:** *Dado* credenciales ausentes o inválidas, *cuando* arranca la aplicación o se envía, *entonces* falla con un error claro y visible, sin degradar a «enviado».
- **CA-013-25:** *Dado* un fallo transitorio del proveedor, *cuando* falla el envío, *entonces* se reintenta con la misma clave de idempotencia y no se envía dos veces.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Inyección de código o HTML en plantillas | Markdown sin HTML, lista blanca de variables, sin Blade del usuario (CA-013-4, 8) |
| Logotipo malicioso | Validación por contenido, sin SVG (CA-013-6) |
| Entregabilidad distinta entre proveedores | Pruebas reales de SPF/DKIM/DMARC antes de cambiar de proveedor |
| Retención excesiva del registro de envíos | DA-3 y anonimización programada (plan 014) |
| Aprobación de SES retrasada | Resend sigue siendo el proveedor; el adaptador se prueba con el sandbox |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
