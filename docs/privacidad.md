# Privacidad y datos personales

> La plataforma guarda datos de asistentes, ponentes, voluntarios y equipos de varias organizaciones. Este documento es una guía de ingeniería, **no asesoría legal**: validar con un asesor la normativa aplicable (en México, la Ley Federal de Protección de Datos Personales en Posesión de los Particulares y su reglamento; fuera de México, la local, y el RGPD si hay titulares europeos) `[RELLENAR: confirmado por asesor legal el AAAA-MM-DD]`.

## 1. Roles en el modelo SaaS

| Rol | Quién | Responsabilidad |
|---|---|---|
| **Responsable** | Cada organización cliente (la ANPR, otra asociación) | Decide para qué se usan los datos de sus asistentes; publica su aviso de privacidad; atiende los derechos de las personas |
| **Encargado** | La plataforma | Trata los datos por cuenta de cada organización, con medidas de seguridad, sin usarlos para fines propios |

Consecuencias de ingeniería: los datos de una organización **nunca** se mezclan con los de otra (ADR-0003); hace falta un contrato de tratamiento de datos con cada organización cliente; y cada organización define su aviso de privacidad y sus plazos de conservación.

## 2. Inventario de datos personales

Mantener esta tabla al día: **todo dato personal nuevo se añade aquí en el mismo cambio que lo introduce** (lista de verificación de `docs/seguridad.md` §10).

| Dato | Módulo | Finalidad | Base legal | Conservación | ¿Sensible? |
|---|---|---|---|---|---|
| Nombre, correo y teléfono de asistentes | Registro | Inscripción y comunicación del evento | Contrato / consentimiento | `[RELLENAR]` | No |
| Cargo, empresa, país, ciudad | Registro y ponentes | Gafete, programa y estadísticas | Consentimiento | `[RELLENAR]` | No |
| Ficha del ponente de un evento (`speakers`): nombre, apellido, correo, país, organización, cargo, descripción de una línea, biografía, fotografía (`photo_path`, disco `media`) y redes sociales (`social_links`), más si está publicada | Programa (plan 021: ponentes) | Armar el programa del evento, publicar al ponente en el sitio público y contactarlo; los datos vienen de su propuesta (nombre, apellido, correo, país, organización, cargo y biografía) o los captura el organizador | Consentimiento expreso para la publicación (el ponente que envía una propuesta consiente al enviarla; el invitado que da de alta el organizador, por declaración del organizador); relación con la organización para la gestión interna | Se publica solo con `is_published`; una reversa de la propuesta lo oculta sin borrarlo; la eliminación es borrado lógico y la definitiva o la anonimización se hace a pedido (H-014-4); la fotografía se borra con la ficha; mientras el ponente lo autorice; sin plazo de eliminación automático (decisión del responsable, 2026-11-18): se conserva hasta que la organización elimine la ficha o la actividad; la baja de una persona se atiende a petición (H-014-4) | No |
| Imagen e icono de una actividad (`event_activities`: `image_path`, `icon_path`, disco `media`) y textos del programa | Programa (plan 021: actividades) | Mostrar la actividad en el programa | Interés legítimo de la organización; la imagen no debe contener datos personales sin consentimiento | Mientras exista la actividad; se borran con ella | No |
| Contenido y archivos de propuestas | Convocatoria | Evaluación | Consentimiento | `[RELLENAR]` | No |
| Calificaciones y comentarios de evaluadores | Comité | Decisión de aceptación | Interés legítimo | `[RELLENAR]` | No |
| Datos de facturación | Pagos | Facturación electrónica | Obligación legal | Plazo fiscal aplicable | No |
| Necesidades de accesibilidad o alimentarias | Registro | Atención en el evento | Consentimiento expreso | Hasta el fin del evento + `[RELLENAR]` | **Posible** (salud) |
| Nombre, correo y país de personas de contacto (`contacts`) | Núcleo (autores y ponentes) | Comunicación con la persona dentro de la organización que la registró | Relación de trabajo con la organización y consentimiento | `[RELLENAR: plazo; el borrado es anonimización, no borrado físico]` | No |
| Secreto de la verificación en dos pasos (cifrado) y códigos de recuperación (con hash, cifrados) de quien accede a los paneles (`users`) | Núcleo (acceso) | Segundo factor del inicio de sesión (plan 014) | Interés legítimo (seguridad de la cuenta) | Mientras exista el usuario; se borran al reiniciar la 2FA; nunca se exportan ni se registran | No |
| Huella de dispositivo en hash (`known_devices`: HMAC de navegador, sistema y red /24; sin agente de usuario ni IP) | Núcleo (acceso) | Avisar de inicios de sesión desde un dispositivo nuevo | Interés legítimo (seguridad de la cuenta) | Mientras exista el usuario (se borra en cascada con la cuenta); `[RELLENAR: plazo de depuración de dispositivos sin uso]` | No |
| IP y hash del correo en la auditoría de accesos (`activity_log`, registro `auth`) | Núcleo (acceso) | Detectar abuso: intentos limitados y restablecimientos | Interés legítimo (seguridad) | `[RELLENAR]`; la limpieza de `activitylog` es de 365 días | No |
| Nombre y correo de los miembros del equipo (`users`) y su pertenencia y rol en cada organización (membresías) | Núcleo (acceso y equipo) | Dar acceso al panel, asignar permisos y atribuir acciones auditadas | Relación de trabajo con la organización (contrato) | Mientras el miembro tenga acceso; la baja desactiva la membresía y conserva el registro para la auditoría; el equipo de plataforma (encargado) ve nombre, correo y membresías de todas las organizaciones, en solo lectura, desde el panel de plataforma (plan 016: no se guarda nada nuevo); `[RELLENAR: plazo de eliminación tras la baja]` | No |
| Texto del aviso de privacidad por evento, quién lo publicó y cuándo (`privacy_notices`: versión, cuerpo, `published_by`, `published_at`) | Núcleo (privacidad) | Dar a las personas el aviso vigente y acreditar qué versión se publicó, por quién y en qué fecha | Obligación legal (aviso de privacidad del responsable) | Las versiones publicadas son inmutables y se conservan como evidencia; `[RELLENAR: plazo]` | No (el texto es de la organización; `published_by` es un miembro del equipo) |
| Archivos de propuestas (`submission_files`: archivo en disco privado con nombre generado, nombre original saneado solo para mostrar, tipo, tamaño, hash del archivo y hash del token de subida; el contenido puede incluir datos personales) | Convocatoria (archivos) | Que el autor sustente su propuesta y que los organizadores la evalúen | Consentimiento al enviar la propuesta | Mientras viva la propuesta; los `pending` sin enviar se borran tras 24 h (H-007-3); `[RELLENAR: plazo]`; se borra o anonimiza con H-014-4 | No (el contenido puede incluir datos personales; sin antivirus en R0, RC-3) |
| Quién dio de alta a un conferencista invitado y la declaración de autorización (`submissions.registered_by_user_id` y `authorization_declared_at`), más sus datos de participante (nombre, correo, país, cargo, empresa, biografía) | Convocatoria (invitados, plan 018; **reemplazado por el plan 021**: ya no se usan, solo pueden existir datos heredados, deuda DT-17) | Acreditar que el organizador registró a la persona con su autorización y quién lo hizo | Consentimiento declarado por el organizador y relación con la organización; el invitado recibe el aviso al acceder al portal (R2) | Como las propuestas: se anonimiza con H-014-4; `[RELLENAR: plazo]` | No |
| Archivo de exportación de propuestas (`organizations/{id}/exports/{uuid}.xlsx` o `.csv`, plan 009): copia temporal con título, estado, fecha, eje, tipo, respuestas del formulario y, del autor principal, **solo nombre y correo** (nunca teléfono, biografía, cargo ni empresa; los archivos adjuntos salen como cantidad, sin enlaces ni tokens). La auditoría de cada exportación (`activity_log`, `submissions_exported`) guarda quién exportó, cuándo, los filtros y el número de filas, sin el contenido | Convocatoria (panel de propuestas) | Que el equipo organizador trabaje la lista fuera de la plataforma | Interés legítimo de la organización (responsable) con el permiso `submissions.export` | El archivo se borra a las 24 horas (tarea `submission-exports:purge`); la auditoría sigue la retención de `activity_log` (365 días); `[RELLENAR: confirmar plazo]` | No (puede incluir datos personales del texto de las respuestas) |
| Comentario opcional de quien decide para el autor de una propuesta (`submissions.author_comment` y `submission_status_changes.author_comment`, texto plano de hasta 1000 caracteres) y motivo interno de una reversa (`submission_status_changes.comment`); el historial guarda además quién decidió y cuándo (plan 012) | Convocatoria (decisiones) | Dar retroalimentación al autor al comunicarle el resultado y acreditar quién decidió qué | Interés legítimo de la organización (responsable) y relación con el autor | El historial no se borra; se anonimiza con la persona (H-014-4, plan 014); la auditoría (`activity_log`) no guarda el texto del comentario, solo si existía; `[RELLENAR: plazo]` | No (texto libre: puede incluir datos personales) |
| Huella no reversible de las personas anonimizadas (`anonymization_log`: HMAC-SHA256 del correo normalizado con una sal de la plataforma y el identificador de la organización, más la fecha). No guarda el correo ni ningún otro dato; no se puede revertir | Núcleo (privacidad, plan 014) | Volver a anonimizar a la persona tras restaurar un respaldo (`app:reapply-anonymizations`) y probar que se atendió su derecho | Obligación legal (atender los derechos de las personas) | Indefinida: es la prueba de que se anonimizó; no contiene datos personales | No |
| Archivo de exportación de los datos de una persona (`organizations/{id}/exports/person-{uuid}.json`, plan 014): copia temporal de TODO lo que la organización guarda de ella (contacto, propuestas y sus archivos como metadatos, ponente, cuenta y evaluaciones que hizo, correos recibidos y su auditoría); nunca secretos, hashes ni datos de otras personas. La auditoría (`person_data_exported`) guarda quién exportó, cuándo, el motivo (lista cerrada) y totales, sin contenido ni correo | Núcleo (privacidad) | Atender el derecho de acceso de la persona | Obligación legal, con el permiso `personal_data.manage` | El enlace dura 10 minutos y el archivo se borra a las 24 horas (`submission-exports:purge`) | **Posible** (contiene todos sus datos) |
| Plazos de retención por categoría (`retention_policies`) | Núcleo (privacidad) | Conservar los datos solo el tiempo declarado | Interés legítimo / obligación legal | Mientras la organización los mantenga; sin plazo no se borra nada | No |
| [RELLENAR] | | | | | |

## 3. Reglas de ingeniería

- **Minimización:** no pedir ni guardar lo que no se usa. Cada campo de un formulario tiene una finalidad en la tabla anterior.
- **Consentimiento registrado:** quién consintió, qué texto y versión, y cuándo. Revocable.
- **Aviso de privacidad** enlazado en cada formulario público; el texto lo define la organización.
- **Derechos de acceso, rectificación, cancelación y oposición:** debe existir una acción para **exportar** y para **eliminar o anonimizar** los datos de una persona dentro de una organización, con auditoría. El borrado respeta los datos que deben conservarse por obligación legal (facturación).
- **Conservación:** cada categoría tiene un plazo; un trabajo programado anonimiza o elimina lo vencido. `[RELLENAR: plazos por categoría]`.
- **Sin datos personales en logs, respuestas ni exportaciones sin permiso** (`docs/seguridad.md`). Los eventos de error no incluyen PII.
- **Datos de prueba:** nunca datos reales en local ni en pruebas. Staging, solo anonimizado.
- **Terceros (subencargados):** cada proveedor que recibe datos (correo, pagos, errores, almacenamiento) se lista abajo, con su país y su función.
- **Transferencias internacionales:** documentar dónde se alojan los datos y bajo qué garantías.
- **Menores de edad:** `[RELLENAR: si habrá registro de menores y bajo qué reglas]`.

## 4. Subencargados

| Proveedor | Función | Datos que recibe | País | Contrato de tratamiento |
|---|---|---|---|---|
| Resend (ADR-0009) | Envío de correos | Nombre, correo, contenido | | |
| `[RELLENAR: pagos]` | Cobros | Datos de pago (los gestiona el proveedor) | | |
| `[RELLENAR: errores]` | Registro de errores | Sin PII | | |
| `[RELLENAR: alojamiento]` | Aplicación y base | Todos | | |

## 5. Procedimientos

- **Solicitud de una persona:** quién la recibe y en cuánto tiempo responde `[RELLENAR: lo decide la organización responsable]`. Cómo se ejecuta (plan 014, quien tenga `personal_data.manage`, por defecto `org_admin`): panel de la organización → «Datos de una persona»; (1) escribir el correo con el que figura la persona y elegir el motivo; (2) «Exportar datos» entrega un enlace de 10 minutos a un archivo JSON con todo lo que la organización guarda de ella; verificar su identidad antes de entregárselo; (3) «Anonimizar» pide volver a escribir el correo y es definitivo. Ver §6. Una persona que escribe desde otro correo, o que figura con varios, se atiende una vez por cada correo.
- **Incidente de seguridad con datos personales:** quién decide, a quién se avisa (organizaciones afectadas, personas, autoridad) y en qué plazo `[RELLENAR]`.
- **Fin de contrato de una organización:** exportación de sus datos y eliminación completa en `[RELLENAR]` días.

## 6. Cómo se anonimiza a una persona (plan 014, H-014-4, DA-4)

`AnonymizePerson` actúa dentro de UNA organización (nunca toca a la misma persona en otra) y localiza a la persona por su correo normalizado y por su vínculo con el contacto. Sustituye los datos personales por valores no identificables (nombre «Persona anonimizada», correo `anonymized-{id}@anonymized.invalid`) y conserva lo que la integridad exige: filas, estados, fechas y calificaciones. `PersonalDataInventory` (con su guardiana `PersonalDataInventoryTest`) declara qué columna de cada modelo se sustituye, cuál se redacta y cuál se conserva; un modelo o una columna nueva sin declarar hace fallar la prueba.

| Dato | Qué pasa |
|---|---|
| Contacto (`contacts`) | Nombre, correo y país sustituidos; `anonymized_at` |
| Participante de una propuesta (`submission_participants`) | Nombre, correo, país, cargo, empresa y biografía sustituidos |
| Ponente (`speakers`) | Ficha sustituida y despublicada; la fotografía se borra del disco |
| Archivos de las propuestas donde es participante principal | Se borran del disco; la ficha conserva tipo, tamaño y fechas |
| Enlaces de acceso del autor | Invalidados (consumidos, caducados y con otro hash) |
| Comentario de la decisión para el autor y motivo interno del historial | Sustituidos; los estados, quién decidió y cuándo se conservan |
| Título y respuestas del formulario de sus propuestas | Se les quita su nombre y su correo |
| Comentarios de evaluaciones | Los que hizo como evaluador, sustituidos (las calificaciones se conservan); los que otros escribieron sobre ella, sin su nombre ni su correo |
| Envíos de correo (`mail_dispatches`) | La huella del destinatario deja de corresponder a un correo |
| Auditoría (`activity_log`) | A las entradas de la organización se les quita su nombre y su correo; las entradas, con su actor y su sujeto (identificadores sin dato personal), se conservan |
| Cuenta de acceso (`users`) | Ver abajo |

**Cuenta de acceso.** La cuenta es global y puede pertenecer a varias organizaciones: si la persona solo pertenece a esta, se anonimiza entera (nombre, correo, contraseña aleatoria —las sesiones abiertas dejan de valer—, segundo factor, dispositivos conocidos, token de restablecimiento, y de la auditoría de accesos de la cuenta se quita la IP y la etiqueta del dispositivo); si pertenece también a otra, **se conserva la cuenta**, pierde su acceso aquí y se anonimiza solo lo suyo en esta organización. Se rechaza todo, sin cambiar nada, si la persona es un usuario de plataforma, es la cuenta de quien lo pide o es el último administrador activo de la organización.

**Límites que hay que conocer** (deuda en `docs/sprints/deuda-tecnica.md`):
- El texto libre solo se limpia de lo que se reconoce: el nombre completo y el correo de la persona. Un dato que escribió sin ellos (un teléfono dentro de una respuesta) no se detecta.
- Los datos de facturación se conservan por plazo fiscal; mientras no exista el módulo de pagos no hay ninguno, y el resultado de la anonimización informa `retained` (vacío hoy).
- Si el bucket tiene el versionado activado, las versiones anteriores de un archivo no las borra la aplicación: hace falta una regla de ciclo de vida que las expire.
- Una sesión de autor ya abierta sobre su propuesta sigue siendo válida hasta que caduca.
- Los respaldos anteriores conservan el dato: ver la reaplicación tras restaurar en `docs/operacion.md` §5.
