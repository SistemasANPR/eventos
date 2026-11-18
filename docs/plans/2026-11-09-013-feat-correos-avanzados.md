# feat: correos avanzados (plantillas por evento, marca, versiones y registro de envíos)

> Plan de la épica E-13 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 013: todos sus criterios son `CA-013-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** RC-4 en parte (logotipo y colores solo en correos; la marca blanca completa queda en R5)

## 1. Objetivo y alcance

Completar el módulo de correos del plan 008: que cada evento adapte los correos a su identidad y a su idioma, con historial, prueba de envío y trazabilidad de lo enviado a cada persona, y sumar Amazon SES como proveedor alterno. Resolución de plantilla: **evento → organización → predeterminada del sistema**.

**Prioridad (R1):** este plan **puede terminar después del cierre de la convocatoria (21-ene-2027)**; ni el plan 011 ni el 012 dependen de él: los correos de resultado y de invitación salen con las plantillas del plan 008. Si R0 se retrasa hasta el 4-dic-2026, este plan es de los primeros en recortarse; lo último en sacrificar es H-013-6 (registro de envíos) y la supresión por rebote o queja.

**Dentro:** plantilla por evento que sobrescribe a la organización; logotipo, colores, pie y firma validados; textos por idioma (es, en, pt); historial de versiones y restaurar; envío de prueba; registro de envíos con estado de entrega (webhooks con firma); lista de supresión por rebote o queja; adaptador de SES.

**Fuera:** campañas masivas y segmentación (R4, E-29); marca blanca completa del sitio público (R5); cualquier ejecución de código escrito por el usuario (nunca).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-013-1 | Como organizador, quiero que la plantilla del evento sobrescriba la de la organización, para adaptar cada evento | M | H-008-4 (si no entró en R0, se hace primero en F1 de este plan) |
| H-013-2 | Como organizador, quiero logotipo, colores, pie y firma, para que el correo lleve mi marca | M | H-013-1 |
| H-013-3 | Como organizador, quiero textos por idioma (es, en, pt), para comunicarme en el idioma del autor | S | H-013-1 |
| H-013-4 | Como organizador, quiero historial de versiones y restaurar una anterior, para deshacer errores | M | H-013-1 |
| H-013-5 | Como organizador, quiero enviar un correo de prueba, para ver el resultado real | S | H-013-1 |
| H-013-6 | Como organizador, quiero un registro de envíos con estado de entrega, para saber qué recibió cada persona | M | H-008-6 |
| H-013-7 | Como líder, quiero enviar también con SES, para no depender de un solo proveedor | S | H-008-6, A3 |

## 2. Punto de partida

Estado esperado al iniciar: catálogo en código, plantillas predeterminadas es/en, personalización por organización de asunto, cuerpo, remitente y responder-a (plan 008, si «Debería» entró), envío por cola con Resend (H-008-6). **El plan 008 ya crea** `mail_templates` con `event_id` nullable, el `CHECK` de `locale` en (es, en, pt), la columna `is_enabled` y los dos índices únicos parciales (uno para el nivel organización, con `event_id` nulo, y otro para el nivel evento); este plan solo los usa. La solicitud de salida del *sandbox* de SES (acción A3 del plan maestro) se abre antes. `[PENDIENTE: estado de la aprobación de SES y cuotas iniciales vigentes]`.

## 3. Decisiones

### DA-1 · Formato del cuerpo — se recomienda **Markdown restringido, sin HTML de entrada**

El cuerpo se escribe en Markdown, se renderiza con CommonMark con HTML deshabilitado y se envuelve en una plantilla de marca fija del sistema (logotipo, colores, pie). Nunca Blade ni PHP del usuario. Alternativa descartada: editor HTML libre, con superficie de ataque mayor.

### DA-2 · Variables — se recomienda **lista blanca por clave de notificación, rechazada al guardar**

Una variable `{{no_listada}}` hace fallar la validación al guardar y nunca se evalúa. Las variables se sustituyen por valores escapados.

### DA-3 · Retención del registro de envíos — se recomienda **plazo fijo y anonimización del destinatario al vencer**

`[PENDIENTE: plazo de retención en días y quién lo aprueba]`; se refleja en `docs/privacidad.md` y la ejecuta el plan 014 (H-014-6).

### DA-4 · Logotipo — se recomienda **PNG o JPG con tamaño máximo, sin SVG, en un prefijo público de marca con nombre generado**

El SVG puede llevar scripts (`docs/seguridad.md` §5). El logotipo se guarda en un prefijo público de marca, **separado del bucket privado de archivos de usuarios**, con nombre generado y solo para archivos validados por contenido. Los correos referencian esa URL permanente. **Nunca** se usa una URL firmada de larga vigencia: se comporta como pública y filtra la firma. Es una excepción acotada y documentada a «archivos privados por defecto», justificada porque el logotipo no es dato personal ni sensible. Alternativa: incrustarlo con `cid:`, que algunos clientes bloquean y aumenta el tamaño de cada correo; se descarta salvo que la prueba de entregabilidad muestre que el prefijo público afecta la reputación. Ruta del prefijo y bucket: `[PENDIENTE: definir con el plan 010]`.

### DA-5 · Supresión por rebote o queja — se recomienda **lista de supresión por organización con regla explícita sobre los críticos**

Una dirección con rebote duro o queja entra en `mail_suppressions`. Se omiten todos los correos **no críticos**. De los críticos, se siguen enviando solo los que la propia persona acaba de pedir (`auth.password_reset` y `submission.access_link`); los demás críticos (acuse, invitaciones, resultados) se omiten y aparecen en una lista «correos suprimidos» para que el organizador contacte a la persona por otro medio. Alternativa descartada: seguir enviando todos los críticos, que degrada la reputación del dominio.

## 4. Contrato

- **Rutas web (Filament):** `MailTemplateResource` con nivel (organización o evento), idioma y versiones; acción «enviar prueba»; página de registro de envíos; lista de correos suprimidos. **Webhook de entrega** del proveedor: propuesta de entrada en `docs/api/endpoints.md` antes de implementar (`POST /api/v1/webhooks/mail-delivery/{provider}`, firma verificada, sin usuario, procesado en cola, idempotente por identificador de evento del proveedor).
- **Contexto de organización del webhook:** cada correo saliente lleva como metadatos o etiquetas del proveedor el `delivery_id` y el `organization_id` (identificadores UUID, no secretos). El trabajo que procesa el webhook toma el `organization_id` del evento **ya verificado por firma**, establece el contexto con `CurrentOrganization::run()` y busca el envío dentro de esa organización. Si no hay metadatos válidos o no coinciden con ningún envío, el evento se descarta con registro y respuesta 2xx, sin `withoutOrganizationScope()` (que no es un caso permitido por el ADR-0003).
- **Form Requests:** `UpdateMailTemplateRequest` (variables en lista blanca, longitudes, idioma permitido), `UploadMailLogoRequest` (tipo MIME real, tamaño, dimensiones), `SendTestMailRequest`, `RestoreMailTemplateVersionRequest`.
- **Actions:** `ResolveMailTemplate` (evento → organización → sistema), `UpdateMailTemplate`, `RestoreMailTemplateVersion`, `SendTestMail`, `RecordMailDelivery`, `SuppressMailRecipient`, `SesMailTransport` detrás de la interfaz de transporte del plan 008.
- **Permisos (nombres `entidad.accion`):** `mail_templates.manage` y `mail_deliveries.view`. El plan 008 usa `mail-templates.view`: debe unificarse a `mail_templates.view` en ese plan.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* personalización por evento, versiones, supresión y trazabilidad de envíos; dueño: módulo de correos.
  2. *Modelo:* `mail_templates` (ya creada por el plan 008; aquí se usa `event_id` nullable); `mail_template_versions` (plantilla, número, contenido, autor, instante UTC; solo se añade); `mail_branding` (organización, evento nullable, ruta del logotipo en el prefijo de marca, colores, pie, firma); `mail_deliveries` (clave, destinatario y su `contact_id`, evento, estado `queued|sent|delivered|bounced|complained|failed`, identificador del proveedor, instante UTC); `mail_suppressions` (organización, correo normalizado, motivo `bounced|complained`, instante UTC, `delivery_id` de origen). La predeterminada del sistema vive en código y semillas, con `MailTemplate` global declarado en `TenantModels::GLOBAL_MODELS`. Ámbito: organización y evento.
  3. *Integridad:* único `(provider, provider_event_id)` en entregas y único `(organization_id, correo normalizado)` en supresiones; claves foráneas con el mismo `organization_id` (con `unique(id, organization_id)` en las tablas referenciadas); índices únicos parciales de `mail_templates` del plan 008.
  4. *Cambio:* tablas y columnas nuevas; las plantillas por organización existentes no cambian de contenido.
  5. *Alternativa descartada:* una plantilla por idioma en columnas separadas; contradice ADR-0005 (traducciones en un registro).
- **Variables de entorno nuevas:** credenciales y región de SES, secreto del webhook; todas en `.env.example` con valores ficticios y en `docs/operacion.md`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin` y coordinador de evento: editar plantillas y marca del evento (`mail_templates.manage`); `org_admin`: plantillas y marca de la organización, ver el registro de envíos y los correos suprimidos (`mail_deliveries.view`). `reviewer` y autores: ninguno |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `MailTemplatePolicy` y `MailDeliveryPolicy`; `tests/Feature/Mail/` con permitido y denegado por rol |
| ¿Modelos nuevos con datos de organización? | Sí (`mail_templates`, `mail_template_versions`, `mail_branding`, `mail_deliveries`, `mail_suppressions`) con `BelongsToOrganization`; la predeterminada es global declarada |
| ¿El acceso cruza eventos o depende de `event_assignments`? | La plantilla de un evento solo la edita quien tiene asignación a ese evento o rol de organización |
| ¿Datos personales nuevos? | Destinatario (correo y contacto), estado de entrega y lista de supresión: base contrato/interés legítimo; retención en DA-3; se anonimizan con la persona (plan 014); proveedor de correo listado como subencargado |
| ¿Se suben archivos? | Logotipo: PNG o JPG, tamaño máximo `[PENDIENTE: límite en KB]`, prefijo público de marca, nombre generado, validación por contenido |
| ¿Hay dinero, pagos o webhooks? | Webhook de entrega: firma verificada antes de procesar, 4xx si no coincide, cola e idempotencia, contexto de organización por metadatos del propio envío; sin dinero |
| ¿Se envían correos? | Sí, todos por cola; el de prueba solo al correo del usuario que lo pide |
| ¿Qué se registra en la auditoría? | Cambios de plantilla y de marca, restauraciones, envíos de prueba, consulta del registro de envíos y altas en la lista de supresión |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos y entrada del webhook aprobadas; pruebas de variables y de sanitización | S |
| F1 | Resolución evento → organización → sistema y plantillas por evento (H-013-1) | M |
| F2 | Marca, logotipo y textos por idioma (H-013-2, H-013-3) | M |
| F3 | Versiones, restaurar y envío de prueba (H-013-4, H-013-5) | M |
| F4 | Registro de envíos, webhooks y supresión (H-013-6) | M |
| F5 | Transporte SES (H-013-7) | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-013-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-013-1 · Plantilla por evento

- **CA-013-1:** *Dado* una plantilla de organización y otra del evento X para la misma clave e idioma, *cuando* se envía una notificación del evento X, *entonces* se usa la del evento; en otro evento de la misma organización se usa la de la organización.
- **CA-013-2:** *Dado* que no existe plantilla de evento ni de organización, *cuando* se envía, *entonces* se usa la predeterminada del sistema en el idioma pedido.
- **CA-013-3:** *Dado* una plantilla del evento de la organización A, *cuando* un usuario de B la pide por su identificador, *entonces* la respuesta es 404.
- **CA-013-4:** *Dado* una plantilla con una variable fuera de la lista blanca, *cuando* se guarda, *entonces* se rechaza con error de validación que nombra la variable.
- **CA-013-5:** *Dado* una plantilla de organización existente para una clave e idioma (o una de evento para el mismo evento), *cuando* se intenta crear una segunda igual, *entonces* la base de datos la rechaza por el índice único parcial correspondiente, aunque `event_id` sea nulo.

### H-013-2 · Marca, logotipo, pie y firma

- **CA-013-6:** *Dado* un logotipo PNG válido dentro del límite, *cuando* se sube, *entonces* se guarda en el prefijo de marca con nombre generado y aparece en la vista previa.
- **CA-013-7:** *Dado* un archivo con extensión `.png` pero contenido SVG, PHP o HTML, *cuando* se sube, *entonces* se rechaza por validación del contenido real.
- **CA-013-8:** *Dado* un color con formato inválido o pie con HTML, *cuando* se guarda, *entonces* el color se rechaza y el pie se sanitiza sin conservar etiquetas.
- **CA-013-9:** *Dado* una notificación crítica, *cuando* se renderiza con marca, *entonces* el cuerpo se genera a partir de Markdown sin HTML de entrada y no se ejecuta ningún código del usuario.
  Manual: revisar el correo real en un cliente de escritorio y en un móvil de ~375 px, con modo oscuro.
- **CA-013-10:** *Dado* un correo con marca, *cuando* se inspecciona su HTML, *entonces* el logotipo se referencia con una URL permanente del prefijo de marca, sin parámetros de firma ni token, y no apunta al bucket privado.

### H-013-3 · Textos por idioma

- **CA-013-11:** *Dado* un evento con idiomas es, en y pt, *cuando* se envía una notificación a un autor cuyo idioma es pt, *entonces* se usa el texto en pt.
- **CA-013-12:** *Dado* un idioma sin texto propio, *cuando* se envía, *entonces* se usa el idioma base español y se registra el respaldo; nunca se envía un correo sin cuerpo.
- **CA-013-13:** *Dado* el catálogo, *cuando* corren las guardianas, *entonces* toda notificación tiene predeterminada en es, en y pt.

### H-013-4 · Versiones

- **CA-013-14:** *Dado* una plantilla editada tres veces, *cuando* se abre su historial, *entonces* aparecen tres versiones con autor e instante UTC, de solo lectura.
- **CA-013-15:** *Dado* una versión anterior, *cuando* el organizador la restaura, *entonces* se crea una versión nueva con ese contenido (el historial no se reescribe) y se audita.
- **CA-013-16:** *Dado* un organizador sin `mail_templates.manage`, *cuando* intenta restaurar, *entonces* se deniega.

### H-013-5 · Envío de prueba

- **CA-013-17:** *Dado* una plantilla en edición, *cuando* el organizador pide una prueba, *entonces* se envía con datos de ejemplo solo al correo del usuario autenticado, con el asunto marcado como prueba.
- **CA-013-18:** *Dado* una petición de prueba con un destinatario distinto en el cuerpo de la solicitud, *cuando* se procesa, *entonces* se ignora el destinatario del cliente y se usa el del usuario autenticado.
- **CA-013-19:** *Dado* un uso repetido, *cuando* supera el límite de peticiones, *entonces* la respuesta es 429.

### H-013-6 · Registro de envíos y supresión

- **CA-013-20:** *Dado* un correo enviado, *cuando* el proveedor notifica la entrega, *entonces* el registro cambia a `delivered` y conserva el identificador del proveedor.
- **CA-013-21:** *Dado* un webhook con firma inválida o ausente, *cuando* llega, *entonces* se rechaza con 4xx y no se modifica ningún registro.
- **CA-013-22:** *Dado* un webhook repetido o desordenado con el mismo identificador de evento, *cuando* se procesa, *entonces* el estado final es correcto y no se duplican registros (idempotencia).
- **CA-013-23:** *Dado* un administrador de A, *cuando* abre el registro de envíos, *entonces* solo ve envíos de A; los destinatarios no aparecen en registros de aplicación.
- **CA-013-24:** *Dado* un rebote o una queja, *cuando* se registra, *entonces* la dirección entra en `mail_suppressions` y los siguientes envíos **no críticos** a esa dirección se omiten con aviso.
- **CA-013-25:** *Dado* una dirección suprimida, *cuando* se envía un correo crítico, *entonces* solo salen `auth.password_reset` y `submission.access_link`; los demás críticos se omiten y aparecen en la lista «correos suprimidos» del organizador.
- **CA-013-26:** *Dado* un webhook con firma válida cuyos metadatos traen un `organization_id` o `delivery_id` que no corresponde a ningún envío de esa organización, *cuando* se procesa, *entonces* se descarta con registro, no modifica ningún dato y no se usa `withoutOrganizationScope()`.

### H-013-7 · Adaptador de SES

- **CA-013-27:** *Dado* la configuración de proveedor `ses`, *cuando* se envía un correo del catálogo, *entonces* sale por SES sin cambiar el código del módulo que lo dispara.
- **CA-013-28:** *Dado* credenciales ausentes o inválidas, *cuando* arranca la aplicación o se envía, *entonces* falla con un error claro y visible, sin degradar a «enviado».
- **CA-013-29:** *Dado* un fallo transitorio del proveedor, *cuando* falla el envío, *entonces* se reintenta con la misma clave de idempotencia y no se envía dos veces.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Inyección de código o HTML en plantillas | Markdown sin HTML, lista blanca de variables, sin Blade del usuario (CA-013-4, 9) |
| Logotipo malicioso | Validación por contenido, sin SVG (CA-013-7) |
| URL de logotipo que filtra una firma | Prefijo público de marca con nombre generado y sin firma (CA-013-10) |
| Entregabilidad distinta entre proveedores | Pruebas reales de SPF/DKIM/DMARC antes de cambiar de proveedor |
| Reputación del dominio por direcciones con queja | Lista de supresión y regla sobre críticos (CA-013-24, 25) |
| Webhook que no encuentra su organización con RLS activa | Metadatos del propio envío y contexto por `CurrentOrganization::run()` (CA-013-26, plan 014) |
| Retención excesiva del registro de envíos | DA-3 y anonimización programada (plan 014) |
| Aprobación de SES retrasada | Resend sigue siendo el proveedor; el adaptador se prueba con el sandbox |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
