# feat: archivos privados de propuestas

> Plan de la épica E-07 (R0). Especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-09
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** RC-3 (sin antivirus en R0; deuda P2 propuesta, decisión el 18-dic-2026, dueño Antonio)

## 1. Objetivo y alcance

Permitir que un autor adjunte archivos a su propuesta (presentación, documento, imagen de póster) y que los organizadores los descarguen, **sin** servir nunca un archivo de usuario desde una ruta pública. En el cpanel de origen las subidas conservaban el nombre original en carpetas públicas y no se validaban (riesgo de ejecutar un `.php`); aquí cada archivo entra por una sola vía: disco privado, nombre generado, validación por contenido, subida ligada a la sesión del formulario y descarga con enlace temporal auditado.

**Dentro:** modelo `SubmissionFile`; servicio de almacenamiento privado compatible con S3; validación por contenido, estructura, extensión y tamaño; subida pública bajo la convocatoria, ligada a un `upload_token` de la sesión del formulario, con cuotas por IP y por token; límites por campo y por propuesta; descarga para organizadores desde el panel con URL temporal firmada y registro en la auditoría; nombre neutro para roles que no deben ver el nombre original; límites de la infraestructura de subida con valores explícitos; tarea programada que borra archivos huérfanos (Debería).

**Fuera:** escaneo antivirus (RC-3, decisión en E-14); vista previa del documento dentro del panel (el plan 009 solo enlaza la descarga); archivos del portal del ponente (R2); logotipos de correos (plan 013); miniaturas e imágenes del sitio público.

### Historias

| Historia | Tam. | Dependencias |
|---|---|---|
| H-007-1 Como autor, quiero adjuntar archivos permitidos a mi propuesta, con límites por campo y por propuesta, para sustentarla | M | H-001-1, H-004-1 |
| H-007-2 Como organizador, quiero descargar los archivos con enlaces temporales y auditados, para evaluarlos | M | H-007-1, H-001-3 |
| H-007-3 Como líder, quiero limpieza de archivos huérfanos, para no acumular basura (Debería) | S | H-007-1 |

## 2. Punto de partida

Al 2026-11-09 no existe el proyecto Laravel (no hay `composer.json` ni `artisan`); solo la plantilla y sus reglas. Las reglas vigentes están en `docs/seguridad.md` §5 (disco privado, nombre generado, validación por contenido, URL temporal, ruta con la organización) y en `app/CLAUDE.md` §8. Este plan depende de que el plan 001 entregue `BelongsToOrganization`, el middleware `SetOrganizationFromRoute` y las Policies; de que el plan 006 emita el token de la sesión del formulario (ver §4); de la acción A5 (almacenamiento S3 compatible) y del spike S-3 del plan maestro (subida al bucket privado desde Railway).

## 3. Decisiones

- **DA-1 · Tipos permitidos** — se recomienda **PDF, PPTX, JPG y PNG** (lista cerrada y configurable por constante de código, no por el usuario). `.ppt`, `.doc*`, `.zip` y cualquier otro quedan fuera de R0.
- **DA-2 · Tamaño máximo** — se recomienda **25 MB por archivo** y **3 archivos por campo de archivo** `[PENDIENTE: confirmar con la ANPR si las presentaciones del congreso superan 25 MB; el cpanel aceptaba hasta ~100 MB]`.
- **DA-3 · Proveedor del bucket** — se recomienda un bucket **privado** compatible con S3 (Cloudflare R2, adoptado salvo objeción) con versionado `[PENDIENTE: A5, verificar precio vigente]`. Con `Storage::fake()` en pruebas.
- **DA-4 · Cómo se descarga** — se recomienda URL temporal firmada **del bucket** que caduca a los **5 minutos**, con `Content-Disposition: attachment` y nombre generado, emitida por una acción del panel autorizada por Policy. Los archivos de usuarios no se sirven desde el dominio de la aplicación y no hay rutas `/orgs/...`.
- **DA-5 · Antivirus (RC-3)** — se recomienda **no escanear en R0** y registrar la deuda P2: se compensa con lista corta de tipos, validación por contenido y estructura, nombre generado, descarga solo por organizadores y como adjunto. Cierra en H-014-5.
- **DA-6 · Archivo subido y no enviado** — se recomienda subir con estado `pending`, ligado al `upload_token` de la sesión del formulario, y ligarlo a la propuesta al enviarla; los `pending` vencidos se borran (H-007-3).
- **DA-7 · Cuotas de subida** — se recomienda **10 subidas por `upload_token` y 30 por IP cada hora**, con respuesta 429. El token de Turnstile no se consume en la subida: solo en el envío final (plan 006).
- **DA-8 · Límites por propuesta** — se recomienda **10 archivos y 100 MB en total** por propuesta, además de los 3 archivos por campo.
- **DA-9 · Infraestructura de subida** — se fijan `upload_max_filesize` en 26 MB, `post_max_size` en 80 MB (3 × 25 MB más margen del formulario) y el mismo tope en el servidor web; el tiempo máximo de la petición en Railway `[PENDIENTE: verificar el límite vigente de la plataforma]`.

## 4. Contrato

- **Form Request:** `UploadSubmissionFileRequest` (archivo, `form_field_key`; tamaño, extensión permitida, tipo MIME real y estructura); el nombre original del cliente nunca se usa como ruta ni como cabecera.
- **Rutas web (sin JSON público nuevo):** `POST /{organization:slug}/events/{event:slug}/call/{call}/files` (subida pública, bajo la convocatoria; el middleware `SetOrganizationFromRoute` del plan 001 fija la organización antes del binding; con límite de peticiones y cuotas de DA-7). La descarga **vive en el panel de Filament**: acción `submission-files.download` acotada al tenant, que responde con una redirección a la URL temporal. No se añade nada a `docs/api/endpoints.md`.
- **Token de subida (contrato con el plan 006):** al renderizar el formulario público, el plan 006 emite un `upload_token` aleatorio ligado a la sesión del formulario; el plan 007 guarda solo su hash en cada archivo y exige que coincida al subir y al adjuntar. El plan 006 lo invoca desde `SubmitSubmission`.
- **Propuesta de datos (`docs/database-design.md` §6):**
  1. *Necesidad:* guardar metadatos de cada archivo; ámbito organización (y evento por la propuesta); vive mientras viva la propuesta.
  2. *Inspección:* sin esquema previo; usa `organizations` (plan 001), `events` y `submissions` (planes 003 y 006).
  3. *Modelo:* `submission_files(id uuid, organization_id uuid NOT NULL FK, event_id uuid NOT NULL FK, submission_id uuid NULL FK, form_field_key string, upload_token_hash string NOT NULL, storage_path string, original_name string, mime_type string, size_bytes bigint, sha256 string, status string CHECK(pending, attached), uploaded_at, deleted_at)`. Único `(organization_id, storage_path)`. La FK compuesta `(event_id, organization_id)` hacia `events` (requiere `unique(id, organization_id)` en `events`, plan 003) y la validación comprueban que archivo, propuesta y evento comparten `organization_id`.
  4. *Integridad:* `SoftDeletes`; índice por `(organization_id, status, uploaded_at)` para la limpieza; `original_name` se guarda solo para mostrar a organizadores, escapado, y nunca se usa como nombre de descarga.
  5. *Cambio:* migración nueva; nada que transformar.
  6. *Alternativa descartada:* guardar la ruta en una columna de la propuesta; no soporta varios archivos por campo ni el estado `pending`.
  7. *Preguntas de negocio:* DA-2.
- **Servicio:** `App\Support\Files\PrivateUploads` (guardar, validar, URL temporal), `SubmissionFileData::forReviewer()` (nombre neutro, sin `original_name`) y Action `AttachSubmissionFile`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Visitante (con formulario abierto): subir archivos al enviar. Organizador con permiso `files.download`: descargar. Nadie más descarga; el autor no descarga en R0. Los roles que no ven la identidad del autor (evaluador ciego, plan 011) solo reciben el nombre neutro. |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `SubmissionFilePolicy` (`download`); prueba por rol, permitido y denegado, en `tests/Feature/Submissions/SubmissionFileDownloadTest.php`. La subida pública no usa Policy de usuario: se acota por convocatoria abierta, `upload_token`, cuotas y límite de peticiones. |
| ¿Hay modelos nuevos con datos de una organización? | Sí: `SubmissionFile` usa `BelongsToOrganization`; entra en las guardianas. |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No en R0; el organizador accede por su rol de organización. Con `event_assignments` (H-011-7) la Policy se acota al evento. |
| ¿Datos personales nuevos? | El contenido del archivo puede contener datos personales (presentaciones, fotos). Base legal: consentimiento al enviar la propuesta; retención según `docs/privacidad.md` `[PENDIENTE: plazo]`; se anonimiza o borra con H-014-4. Se añade a `docs/privacidad.md` en el mismo cambio. |
| ¿Se suben archivos? | Sí. PDF, PPTX, JPG, PNG; 25 MB por archivo, 3 por campo, 10 y 100 MB por propuesta (DA-1, DA-2, DA-8); disco privado; nombre `uuid`; ruta `organizations/{organization_id}/events/{event_id}/files/{uuid}`. |
| ¿Hay dinero, pagos o webhooks? | No. |
| ¿Se envían correos o notificaciones? | No. |
| ¿Qué se registra en la auditoría? | Cada descarga (quién, qué archivo, cuándo), intentos denegados de descarga y la limpieza de huérfanos (conteo por organización). Sin nombres de archivo en logs de aplicación. |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Spike S-3: subir y firmar una URL contra el bucket desde Railway `staging`; límites de infraestructura de DA-9; interfaz `PrivateUploads` con `Storage::fake()` | S |
| F1 | H-007-1: modelo, migración propuesta, validación por contenido y estructura, `upload_token`, cuotas y límites | M |
| F2 | H-007-2: Policy, acción de descarga del panel con URL temporal y auditoría | M |
| F3 | H-007-3 (Debería): tarea de limpieza multi-organización | S |

## 7. Criterios de aceptación

### H-007-1 · Adjuntar archivos

- **CA-007-1:** *Dado* una convocatoria abierta con un campo de archivo y un `upload_token` válido, *cuando* se sube un PDF válido de 1 MB, *entonces* se guarda en el disco privado con nombre `uuid`, en la ruta de su organización, con estado `pending`, el hash del token y sin usar el nombre original como ruta.
- **CA-007-2:** *Dado* un archivo `.php` renombrado como `informe.pdf`, *cuando* se sube, *entonces* se rechaza por su contenido real con un error de validación y no queda nada en el disco ni en la base.
- **CA-007-3:** *Dado* archivos `.php`, `.phtml`, `.phar`, `.html`, `.svg`, `.exe` o `.zip`, *cuando* se suben, *entonces* se rechazan todos, aunque su cabecera declare un tipo permitido.
- **CA-007-4:** *Dado* un archivo de 25 MB más un byte, *cuando* se sube, *entonces* se rechaza con el límite en el mensaje; uno de exactamente 25 MB se acepta.
- **CA-007-5:** *Dado* un archivo cuyo nombre original contiene `../` o caracteres de control, *cuando* se sube, *entonces* se guarda con el nombre generado y el nombre original se almacena solo como dato de visualización, escapado al mostrarse.
- **CA-007-6:** *Dado* una convocatoria cerrada o un campo de archivo inexistente en el formulario, *cuando* se intenta subir, *entonces* se responde 422 sin guardar nada.
- **CA-007-7:** *Dado* un archivo PPTX con la estructura OOXML válida (`[Content_Types].xml` y directorio `ppt/`), *cuando* se sube, *entonces* se acepta.
- **CA-007-8:** *Dado* un ZIP arbitrario renombrado como `diapositivas.pptx` (sin `[Content_Types].xml` o sin `ppt/`), *cuando* se sube, *entonces* se rechaza con un error de validación y no queda nada guardado.
- **CA-007-9:** *Dado* un archivo con cabecera de PDF, JPG o PNG válida que contiene código ejecutable (`<?php`) o un ZIP concatenado (políglota), *cuando* se sube, *entonces* se rechaza con un error de validación y no queda nada guardado.
- **CA-007-10:** *Dado* una subida sin `upload_token` o con un token que no corresponde a la sesión del formulario, *cuando* se envía, *entonces* se responde 403 y no se guarda nada.
- **CA-007-11:** *Dado* un archivo `pending` subido con el token de otra sesión, *cuando* un envío intenta adjuntarlo, *entonces* se rechaza con un error de validación que no revela que el archivo existe y no queda adjunto.
- **CA-007-12:** *Dado* un archivo ya `attached` a una propuesta, *cuando* otro envío intenta adjuntarlo, *entonces* se rechaza con un error de validación y el archivo sigue ligado a la primera propuesta.
- **CA-007-13:** *Dado* un `upload_token` con 10 subidas en la última hora, o una IP con 30, *cuando* intenta una más, *entonces* se responde 429 y no se guarda nada.
- **CA-007-14:** *Dado* un campo de archivo con máximo de 3, *cuando* se intenta adjuntar un cuarto, *entonces* se rechaza con un error de validación.
- **CA-007-15:** *Dado* una propuesta con 10 archivos o 100 MB acumulados, *cuando* se intenta adjuntar uno más, *entonces* se rechaza con un error de validación que indica el límite.
- **CA-007-16:** *Dado* la configuración de `staging` con `upload_max_filesize` de 26 MB y `post_max_size` de 80 MB, *cuando* se suben tres archivos de 25 MB en un solo envío, *entonces* terminan sin error 413 ni expiración de la petición.
  Manual: en `staging`, abrir la convocatoria a ~1280 px, adjuntar tres archivos de 25 MB y completar el envío; repetir con un cuarto archivo para ver el rechazo por límite y no por la infraestructura.

### H-007-2 · Descarga por organizadores

- **CA-007-17:** *Dado* un organizador con el permiso `files.download`, *cuando* pide la descarga de un archivo de su organización desde el panel, *entonces* recibe una redirección a una URL temporal firmada con `Content-Disposition: attachment` que caduca a los 5 minutos.
- **CA-007-18:** *Dado* un archivo de la organización B, *cuando* un organizador de A pide su identificador, *entonces* la respuesta es 404, no 403.
- **CA-007-19:** *Dado* un usuario con sesión sin el permiso `files.download`, *cuando* pide la descarga, *entonces* recibe 403 y la solicitud queda auditada; sin sesión, se redirige al inicio de sesión.
- **CA-007-20:** *Dado* una descarga permitida, *cuando* ocurre, *entonces* la auditoría registra quién, qué archivo y cuándo, sin el contenido.
- **CA-007-21:** *Dado* una URL temporal ya caducada, *cuando* se usa, *entonces* el bucket la rechaza y el archivo no se entrega.
  Manual: en `staging`, generar el enlace, esperar a que caduque y abrirlo; comprobar el rechazo del proveedor. Comprobar también que la ruta pública del bucket sin firma responde 403.
- **CA-007-22:** *Dado* un archivo cuyo `original_name` contiene comillas, `;`, saltos de línea, caracteres Unicode o una doble extensión (`a".pdf.exe`), *cuando* se descarga, *entonces* `Content-Disposition` usa solo el nombre generado (`uuid` y la extensión validada) y la cabecera no se altera.
- **CA-007-23:** *Dado* un rol que no debe ver la identidad del autor, *cuando* consulta un archivo mediante `SubmissionFileData::forReviewer()`, *entonces* recibe un nombre neutro (`archivo-1.pdf`) y nunca `original_name`.
- **CA-007-24:** *Dado* un archivo en estado `pending` (sin propuesta), *cuando* un organizador pide su descarga, *entonces* la respuesta es 404.

### H-007-3 · Limpieza de huérfanos (Debería)

- **CA-007-25:** *Dado* archivos `pending` con más de 24 horas, *cuando* corre la tarea programada, *entonces* se borran del disco y se marcan como eliminados; los `attached` no se tocan.
- **CA-007-26:** *Dado* la tarea de limpieza, *cuando* corre dos veces seguidas, *entonces* la segunda no falla ni borra nada adicional (idempotente), y falla visiblemente si el disco no responde en lugar de reportar cero.
- **CA-007-27:** *Dado* archivos `pending` vencidos en las organizaciones A y B, *cuando* corre la tarea sin contexto de organización, *entonces* recorre cada organización con `CurrentOrganization::run()`, limpia ambas sin lanzar `MissingOrganizationContext` y registra el conteo por organización.
- **CA-007-28:** *Dado* un error al limpiar la organización A, *cuando* corre la tarea, *entonces* el error se reporta de forma visible y la organización B se limpia igualmente.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Archivo malicioso aceptado sin antivirus (RC-3) | Lista corta de tipos, validación por contenido y estructura, descarga solo por organizadores y como adjunto; decisión de escaneo en H-014-5 |
| Un bot llena el bucket con subidas anónimas | `upload_token` por sesión del formulario, cuotas por token y por IP, límites por propuesta |
| Archivos pesados que saturan la cola o el disco | Límite de tamaño y de cantidad; limpieza de huérfanos (Debería; si no entra en R0, el runbook de H-010-4 incluye una limpieza manual) |
| Subidas válidas que fallan en producción por límites de la infraestructura | DA-9 con valores explícitos y CA-007-16 en `staging` |
| Enlace temporal compartido por un organizador | Caducidad de 5 minutos y auditoría de cada emisión |
| El bucket queda público por error | Prueba manual de acceso sin firma en cada entorno; versionado activado |
| Presentaciones reales mayores al límite | DA-2 pendiente con la ANPR |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F1 (H-007-1) | sin commit aún | 2026-11-10 | Migración `submission_files` (aplicada solo a la base local), modelo, fábrica, `PrivateUploads` (validación por contenido), Actions `UploadSubmissionFile` y `AttachSubmissionFile`, ruta `POST .../call/{call}/files`. CA-007-1 a 15 con prueba (`tests/Feature/Submissions/`); CA-007-16 manual en `staging`. Salida real: ver el mensaje de cierre de la sesión. **Pendiente por depender de otros planes:** (1) la FK de `submission_id` hacia `submissions` la añade el plan 006; (2) `FileFieldRegistry` (campo de archivo existente, plan 005) y `UploadTokenVerifier` (emisión del `upload_token`, plan 006) tienen hoy una implementación que deniega todo, por lo que la ruta responde 403 hasta que existan; (3) la variable `PRIVATE_UPLOADS_DISK` (por defecto `local`) falta documentarla en `.env.example` y `docs/operacion.md`. La subida se acota además con `throttle:60,1` por IP en la ruta. |
