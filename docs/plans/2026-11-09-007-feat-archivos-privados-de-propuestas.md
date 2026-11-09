# feat: archivos privados de propuestas

> Plan de la épica E-07 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** RC-3 (sin antivirus en R0; deuda P2 propuesta, decisión el 18-dic-2026, dueño Antonio)

## 1. Objetivo y alcance

Permitir que un autor adjunte archivos a su propuesta (presentación, documento, imagen de póster) y que los organizadores los descarguen, **sin** servir nunca un archivo de usuario desde una ruta pública. En el cpanel de origen las subidas conservaban el nombre original en carpetas públicas y no se validaban (riesgo de ejecutar un `.php`); aquí cada archivo entra por una sola vía: disco privado, nombre generado, validación por contenido y descarga con enlace temporal auditado.

**Dentro:** modelo `SubmissionFile`; servicio de almacenamiento privado compatible con S3; validación por contenido, extensión y tamaño; subida desde el formulario público; descarga para organizadores con URL temporal firmada y registro en la auditoría; límites por propuesta; tarea programada que borra archivos huérfanos.

**Fuera:** escaneo antivirus (RC-3, decisión en E-14); vista previa del documento dentro del panel (plan 009 solo enlaza la descarga); archivos del portal del ponente (R2); logotipos de correos (plan 013); miniaturas e imágenes del sitio público.

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-007-1 Como autor, quiero adjuntar archivos permitidos a mi propuesta, para sustentarla | M | H-001-1 |
| H-007-2 Como organizador, quiero descargar los archivos con enlaces temporales y auditados, para evaluarlos | M | H-007-1, H-001-3 |
| H-007-3 Como líder, quiero límites por propuesta y limpieza de archivos huérfanos, para no acumular abuso ni basura | S | H-007-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel (no hay `composer.json` ni `artisan`); solo la plantilla y sus reglas. Las reglas vigentes están en `docs/seguridad.md` §5 (disco privado, nombre generado, validación por contenido, URL temporal, ruta con la organización) y en `app/CLAUDE.md` §8. Este plan depende de que el plan 001 entregue `BelongsToOrganization` y las Policies, y de la acción A5 (almacenamiento S3 compatible) y del spike S-3 del plan maestro (subida al bucket privado desde Railway).

## 3. Decisiones

- **DA-1 · Tipos permitidos** — se recomienda **PDF, PPTX, JPG y PNG** (lista cerrada y configurable por constante de código, no por el usuario). `.ppt`, `.doc*`, `.zip` y cualquier otro quedan fuera de R0.
- **DA-2 · Tamaño máximo** — se recomienda **25 MB por archivo** y **3 archivos por campo de archivo** `[PENDIENTE: confirmar con la ANPR si las presentaciones del congreso superan 25 MB; el cpanel aceptaba hasta ~100 MB]`.
- **DA-3 · Proveedor del bucket** — se recomienda un bucket **privado** compatible con S3 (Cloudflare R2 o S3) con versionado `[PENDIENTE: A5, proveedor]`. Con `Storage::fake()` en pruebas.
- **DA-4 · Cómo se descarga** — se recomienda URL temporal firmada **del bucket** (caducidad corta) con `Content-Disposition: attachment`, emitida por una ruta autorizada por Policy. Los archivos de usuarios no se sirven desde el dominio de la aplicación.
- **DA-5 · Antivirus (RC-3)** — se recomienda **no escanear en R0** y registrar la deuda P2: se compensa con lista corta de tipos, validación por contenido, nombre generado, descarga solo por organizadores y como adjunto. Cierra en H-014-5.
- **DA-6 · Archivo subido y no enviado** — se recomienda subir con estado `pending` y ligarlo a la propuesta al enviarla; los `pending` vencidos se borran (H-007-3).

## 4. Contrato

- **Form Request:** `UploadSubmissionFileRequest` (archivo, `form_field_key`; tamaño, extensión permitida y tipo MIME real); el nombre original del cliente nunca se usa como ruta.
- **Rutas web (sin JSON público nuevo):** `POST /events/{event}/call-for-submissions/files` (subida pública, con límite de peticiones y anti-robots del plan 006) y `GET /orgs/{organization}/submission-files/{submissionFile}/download` (organizadores; responde con redirección a la URL temporal). No se añade nada a `docs/api/endpoints.md`.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* guardar metadatos de cada archivo; ámbito organización (y evento por la propuesta); vive mientras viva la propuesta.
  2. *Inspección:* sin esquema previo; usa `organizations` (plan 001) y `submissions` (plan 006).
  3. *Modelo:* `submission_files(id uuid, organization_id uuid NOT NULL FK, event_id uuid NOT NULL FK, submission_id uuid NULL FK, form_field_key string, storage_path string, original_name string, mime_type string, size_bytes bigint, sha256 string, status string CHECK(pending, attached), uploaded_at, deleted_at)`. Único `(organization_id, storage_path)`. La FK compuesta o la validación comprueban que archivo, propuesta y evento comparten `organization_id`.
  4. *Integridad:* `SoftDeletes`; índice por `(organization_id, status, uploaded_at)` para la limpieza; `original_name` se guarda solo para mostrar, escapado.
  5. *Cambio:* migración nueva; nada que transformar.
  6. *Alternativa descartada:* guardar la ruta en una columna de la propuesta; no soporta varios archivos por campo ni el estado `pending`.
  7. *Preguntas de negocio:* DA-2.
- **Servicio:** `App\Support\Files\PrivateUploads` (guardar, validar, URL temporal) y Action `AttachSubmissionFile`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Visitante (con propuesta en curso): subir archivos al enviar. Organizador con permiso `submissions.view`: descargar. Nadie más descarga; el autor no descarga en R0. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionFilePolicy` (`download`); prueba por rol, permitido y denegado, en `tests/Feature/Submissions/SubmissionFileDownloadTest.php`. La subida pública no usa Policy de usuario: se acota por convocatoria abierta y límite de peticiones. |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `SubmissionFile` usa `BelongsToOrganization`; entra en las guardianas. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; el organizador accede por su rol de organización. Con `event_assignments` (H-011-7) la Policy se acota al evento. |
| ¿Datos personales nuevos? | El contenido del archivo puede contener datos personales (presentaciones, fotos). Base legal: consentimiento al enviar la propuesta; retención según `docs/privacidad.md` `[PENDIENTE: plazo]`; se anonimiza o borra con H-014-4. Se añade a `docs/privacidad.md` en el mismo cambio. |
| ¿Se suben archivos? | Sí. PDF, PPTX, JPG, PNG; 25 MB por archivo y 3 por campo (DA-1, DA-2); disco privado; nombre `uuid`; ruta `organizations/{organization_id}/events/{event_id}/submissions/{uuid}`. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | Cada descarga (quién, qué archivo, cuándo), intentos denegados de descarga y la limpieza de huérfanos (conteo). Sin nombres de archivo en logs de aplicación. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spike S-3: subir y firmar una URL contra el bucket desde Railway `staging`; interfaz `PrivateUploads` con `Storage::fake()` | S |
| F1 | H-007-1: modelo, migración propuesta, validación por contenido, subida con estado `pending` | M |
| F2 | H-007-2: Policy, ruta de descarga con URL temporal y auditoría | M |
| F3 | H-007-3: límites por propuesta y tarea de limpieza | S |

## 7. Criterios de aceptación

### H-007-1 · Adjuntar archivos

- **CA-007-1:** *Dado* una convocatoria abierta con un campo de archivo, *cuando* se sube un PDF válido de 1 MB, *entonces* se guarda en el disco privado con nombre `uuid`, en la ruta de su organización, con estado `pending` y sin usar el nombre original como ruta.
- **CA-007-2:** *Dado* un archivo `.php` renombrado como `informe.pdf`, *cuando* se sube, *entonces* se rechaza por su contenido real con un error de validación y no queda nada en el disco ni en la base.
- **CA-007-3:** *Dado* archivos `.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.exe` o `.zip`, *cuando* se suben, *entonces* se rechazan todos, aunque su cabecera declare un tipo permitido.
- **CA-007-4:** *Dado* un archivo de 25 MB más un byte, *cuando* se sube, *entonces* se rechaza con el límite en el mensaje; uno de exactamente 25 MB se acepta.
- **CA-007-5:** *Dado* un archivo cuyo nombre original contiene `../` o caracteres de control, *cuando* se sube, *entonces* se guarda con el nombre generado y el nombre original se almacena solo como dato de visualización, escapado al mostrarse.
- **CA-007-6:** *Dado* una convocatoria cerrada o un campo de archivo inexistente en el formulario, *cuando* se intenta subir, *entonces* se responde 422 o 404 sin guardar nada.

### H-007-2 · Descarga por organizadores

- **CA-007-7:** *Dado* un organizador con permiso de ver propuestas, *cuando* pide la descarga de un archivo de su organización, *entonces* recibe una redirección a una URL temporal firmada con `Content-Disposition: attachment` que caduca en pocos minutos `[PENDIENTE: caducidad exacta]`.
- **CA-007-8:** *Dado* un archivo de la organización B, *cuando* un organizador de A pide su identificador, *entonces* la respuesta es 404, no 403.
- **CA-007-9:** *Dado* un usuario sin permiso `submissions.view` (por ejemplo, un miembro sin ese rol) o sin sesión, *cuando* pide la descarga, *entonces* recibe 403 o redirección al inicio de sesión, y la solicitud queda auditada.
- **CA-007-10:** *Dado* una descarga permitida, *cuando* ocurre, *entonces* la auditoría registra quién, qué archivo y cuándo, sin el contenido.
- **CA-007-11:** *Dado* una URL temporal ya caducada, *cuando* se usa, *entonces* el bucket la rechaza y el archivo no se entrega.
  Manual: en `staging`, generar el enlace, esperar a que caduque y abrirlo; comprobar el rechazo del proveedor. Comprobar también que la ruta pública del bucket sin firma responde 403.

### H-007-3 · Límites y limpieza

- **CA-007-12:** *Dado* un campo de archivo con máximo de 3, *cuando* se intenta adjuntar un cuarto, *entonces* se rechaza con un error de validación.
- **CA-007-13:** *Dado* archivos `pending` con más de 24 horas `[PENDIENTE: confirmar ventana]`, *cuando* corre la tarea programada, *entonces* se borran del disco y se marcan como eliminados; los `attached` no se tocan.
- **CA-007-14:** *Dado* la tarea de limpieza, *cuando* corre dos veces seguidas, *entonces* la segunda no falla ni borra nada adicional (idempotente), y falla visiblemente si el disco no responde en lugar de reportar cero.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Archivo malicioso aceptado sin antivirus (RC-3) | Lista corta de tipos, validación por contenido, descarga solo por organizadores y como adjunto; decisión de escaneo en H-014-5 |
| Archivos pesados que saturan la cola o el disco | Límite de tamaño y de cantidad; limpieza de huérfanos |
| Enlace temporal compartido por un organizador | Caducidad corta y auditoría de cada emisión |
| El bucket queda público por error | Prueba manual de acceso sin firma en cada entorno; versionado activado |
| Presentaciones reales mayores al límite | DA-2 pendiente con la ANPR |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
