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
| Biografía y fotografía de ponentes | Conferencistas | Publicación en el programa | Consentimiento expreso | Mientras el ponente lo autorice | No |
| Contenido y archivos de propuestas | Convocatoria | Evaluación | Consentimiento | `[RELLENAR]` | No |
| Calificaciones y comentarios de evaluadores | Comité | Decisión de aceptación | Interés legítimo | `[RELLENAR]` | No |
| Datos de facturación | Pagos | Facturación electrónica | Obligación legal | Plazo fiscal aplicable | No |
| Necesidades de accesibilidad o alimentarias | Registro | Atención en el evento | Consentimiento expreso | Hasta el fin del evento + `[RELLENAR]` | **Posible** (salud) |
| Nombre, correo y país de personas de contacto (`contacts`) | Núcleo (autores y ponentes) | Comunicación con la persona dentro de la organización que la registró | Relación de trabajo con la organización y consentimiento | `[RELLENAR: plazo; el borrado es anonimización, no borrado físico]` | No |
| Huella de dispositivo en hash (`known_devices`: HMAC de navegador, sistema y red /24; sin agente de usuario ni IP) | Núcleo (acceso) | Avisar de inicios de sesión desde un dispositivo nuevo | Interés legítimo (seguridad de la cuenta) | Mientras exista el usuario (se borra en cascada con la cuenta); `[RELLENAR: plazo de depuración de dispositivos sin uso]` | No |
| IP y hash del correo en la auditoría de accesos (`activity_log`, registro `auth`) | Núcleo (acceso) | Detectar abuso: intentos limitados y restablecimientos | Interés legítimo (seguridad) | `[RELLENAR]`; la limpieza de `activitylog` es de 365 días | No |
| Nombre y correo de los miembros del equipo (`users`) y su pertenencia y rol en cada organización (membresías) | Núcleo (acceso y equipo) | Dar acceso al panel, asignar permisos y atribuir acciones auditadas | Relación de trabajo con la organización (contrato) | Mientras el miembro tenga acceso; la baja desactiva la membresía y conserva el registro para la auditoría; `[RELLENAR: plazo de eliminación tras la baja]` | No |
| Texto del aviso de privacidad por evento, quién lo publicó y cuándo (`privacy_notices`: versión, cuerpo, `published_by`, `published_at`) | Núcleo (privacidad) | Dar a las personas el aviso vigente y acreditar qué versión se publicó, por quién y en qué fecha | Obligación legal (aviso de privacidad del responsable) | Las versiones publicadas son inmutables y se conservan como evidencia; `[RELLENAR: plazo]` | No (el texto es de la organización; `published_by` es un miembro del equipo) |
| Archivos de propuestas (`submission_files`: archivo en disco privado con nombre generado, nombre original saneado solo para mostrar, tipo, tamaño, hash del archivo y hash del token de subida; el contenido puede incluir datos personales) | Convocatoria (archivos) | Que el autor sustente su propuesta y que los organizadores la evalúen | Consentimiento al enviar la propuesta | Mientras viva la propuesta; los `pending` sin enviar se borran tras 24 h (H-007-3); `[RELLENAR: plazo]`; se borra o anonimiza con H-014-4 | No (el contenido puede incluir datos personales; sin antivirus en R0, RC-3) |
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
| `[RELLENAR: correo transaccional]` | Envío de correos | Nombre, correo, contenido | | |
| `[RELLENAR: pagos]` | Cobros | Datos de pago (los gestiona el proveedor) | | |
| `[RELLENAR: errores]` | Registro de errores | Sin PII | | |
| `[RELLENAR: alojamiento]` | Aplicación y base | Todos | | |

## 5. Procedimientos

- **Solicitud de una persona:** quién la recibe, en cuánto tiempo responde y cómo se ejecuta la exportación o el borrado `[RELLENAR]`.
- **Incidente de seguridad con datos personales:** quién decide, a quién se avisa (organizaciones afectadas, personas, autoridad) y en qué plazo `[RELLENAR]`.
- **Fin de contrato de una organización:** exportación de sus datos y eliminación completa en `[RELLENAR]` días.
