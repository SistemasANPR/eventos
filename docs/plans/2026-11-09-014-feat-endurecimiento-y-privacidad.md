# feat: endurecimiento y privacidad (2FA, RLS, restauración y derechos de las personas)

> Plan de la épica E-14 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 014: todos sus criterios son `CA-014-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** cierra RC-1 (RLS, P1), RC-2 (2FA, P1) y RC-3 (escaneo de archivos, P2) de `docs/plan-de-desarrollo.md` §3.4; estos puntos se registran en `docs/sprints/deuda-tecnica.md` al aprobar el ADR de recortes de seguridad

## 1. Objetivo y alcance

Cerrar los recortes de seguridad aceptados para lanzar R0: 2FA obligatoria para todo rol con acceso a datos de autores, RLS de PostgreSQL como segunda barrera de aislamiento, restauración de respaldos ensayada con runbooks, y las herramientas de privacidad (exportar, anonimizar, retener) que `docs/privacidad.md` exige. Dueño de los recortes: Antonio.

**Prioridad y fechas (R1):** **H-014-1 (2FA) bloquea la invitación de evaluadores externos** (H-011-3 y H-011-8 dependen de ella) y su fecha límite sigue siendo el 18-dic-2026 (RC-2). El resto del plan mantiene como objetivo el 18-dic-2026 (RC-1 y RC-3) y puede terminar **después del cierre de la convocatoria (21-ene-2027)** sin bloquear la evaluación (planes 011 y 012), salvo que antes entre una segunda organización: ese día la RLS (H-014-2) debe estar activa. Cualquier cambio de esas fechas límite se hace con el ADR de recortes, no aquí. Si R0 se retrasa hasta el 4-dic-2026 (plan B), el orden es H-014-1, H-014-2, H-014-3, H-014-4, H-014-6, H-014-5.

**Dentro:** 2FA; RLS con `SET LOCAL app.organization_id` y pruebas de acceso permitido y denegado, incluidos jobs, planificador, webhook de entrega e inicio de sesión (incluye el antiguo CA-001-12, ahora CA-014-6); ensayo de restauración y runbooks en `docs/operacion.md`; exportación y anonimización de los datos de una persona con inventario de fuentes; decisión sobre el escaneo de archivos; retención y anonimización programadas.

**Fuera:** SSO o inicio de sesión social; pagos y su conciliación (R3); marca blanca. El aviso por correo de inicio de sesión desde un dispositivo nuevo es compensación de RC-2 **en R0** y se especifica en el plan 001 (se propone añadirlo allí); este plan no lo duplica.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-014-1 | Como administrador, quiero verificación en dos pasos obligatoria para todo rol con acceso a datos de autores, para proteger las cuentas con más acceso | M | H-001-4 |
| H-014-2 | Como líder, quiero RLS de PostgreSQL como segunda barrera de aislamiento, para protegerme de un fallo del scope | M | H-001-1, H-001-2 |
| H-014-3 | Como líder, quiero ensayar la restauración de un respaldo y documentar los runbooks, para recuperar el servicio | S | H-010-3 |
| H-014-4 | Como organización, quiero exportar y anonimizar los datos de una persona, para atender sus derechos | M | H-006-1 |
| H-014-5 | Como líder, quiero decidir sobre el escaneo de archivos, para cerrar RC-3 | S | H-007-1 |
| H-014-6 | Como organización, quiero retención y anonimización programadas, para no guardar datos de más | M | H-014-4 |

## 2. Punto de partida

Estado esperado al iniciar: R0 en producción con un solo tenant real, guardianas en verde, autenticación con límite de intentos y sesión endurecida (plan 001), respaldos diarios (plan 010) con una restauración probada en `staging` antes del lanzamiento. Falta, y se mide al iniciar: segunda barrera de aislamiento, 2FA, restauración a fondo, borrado y exportación por persona, retención. `[PENDIENTE: plazos de retención por categoría y quién los aprueba]`.

## 3. Decisiones

### DA-1 · Método de 2FA — se recomienda **TOTP con códigos de recuperación**

Aplicación autenticadora y códigos de un solo uso; el correo no es segundo factor. Se usa lo que ofrece el framework o el kit de inicio y Filament; no se escribe criptografía propia. Alternativa descartada: SMS, por costo y riesgo de intercambio de SIM.

**Alcance (cerrada):** la 2FA es **obligatoria para todo rol con acceso a datos de autores**: `org_admin`, `organizer`, `reviewer` y `platform`. Los autores, que no tienen cuenta, no la usan.

**Recuperación:** si no hay otro administrador que pueda reiniciarla, el reinicio lo hace el rol de plataforma (soporte) con **verificación fuera de banda** (confirmación por un canal distinto al correo de la cuenta, por ejemplo una llamada o un mensaje a un contacto previamente registrado) y queda auditado.

### DA-2 · RLS — se recomienda **política por tabla con `app.organization_id`, un rol de aplicación sin `BYPASSRLS` y un contrato de contexto por tipo de ejecución**

`SET LOCAL app.organization_id` solo tiene efecto **dentro de una transacción**: `CurrentOrganization::run()` abre la transacción y fija el valor con `set_config('app.organization_id', ..., true)`; fuera de `run()` toda consulta a una tabla con tenant devuelve cero filas. Contrato por ejecución:

- **Petición HTTP:** el middleware que resuelve la organización envuelve el ciclo en `run()`.
- **Job en cola:** un middleware de job establece el contexto desde el `organization_id` del propio job y abre la transacción.
- **Tareas programadas:** el planificador recorre las organizaciones y ejecuta cada una dentro de `run()` (recordatorios del plan 012, limpieza de archivos del plan 007, retención de este plan).
- **Webhook de entrega (plan 013):** toma el `organization_id` de los metadatos del envío, verificados por la firma, y llama a `run()`.
- **Inicio de sesión y tablas de plataforma:** `users`, `organization_user`, roles y permisos, `sessions`, `password_reset_tokens`, colas, cachés y las plantillas globales del sistema quedan **fuera de RLS** y se listan en una lista cerrada; su aislamiento lo dan las Policies y la autenticación. Una tabla nueva sin política y fuera de esa lista hace fallar la guardiana.

El rol de migraciones es distinto del de la aplicación. RLS complementa y no reemplaza el scope y las Policies (`docs/database-design.md` §3). Despliegue gradual: primero `staging`, luego producción.

### DA-3 · Escaneo de archivos — se recomienda **decidir con dos opciones medidas**

(a) escaneo asíncrono con un servicio de antivirus antes de que un organizador descargue; (b) seguir sin escaneo con las compensaciones de RC-3. `[PENDIENTE: decisión de Antonio con base en el volumen de archivos y el costo]`. Se registra como ADR corto.

### DA-4 · Anonimizar o borrar — se recomienda **anonimizar** (sustituir datos personales por valores no identificables) y **borrar los archivos** que la persona subió

Se conservan los agregados y la evidencia no personal (tipo, tamaño, estado, fechas). Los archivos subidos por la persona pueden contener datos personales: se eliminan del bucket, **incluidas sus versiones**. Se borra de forma definitiva solo lo que no obligue a conservar la ley (datos de facturación se conservan por plazo fiscal, ver `docs/privacidad.md`). Alternativa descartada: conservar los archivos y solo anonimizar la fila, que deja el dato personal en el bucket.

### DA-5 · Respaldos y derechos de las personas — se recomienda **registro de anonimizaciones que se reaplica tras restaurar**

Cada anonimización deja una huella no reversible (hash del correo normalizado con sal por organización, fecha, organización). Tras restaurar un respaldo, el comando `app:reapply-anonymizations` vuelve a anonimizar a esas personas en los datos restaurados. Los respaldos tienen una retención máxima declarada (`retention_policies`, categoría `backups`): `[PENDIENTE: días de retención de respaldos]`.

## 4. Contrato

- **Rutas web:** configuración y desafío de 2FA en los paneles (Filament); acciones «exportar datos» y «anonimizar» sobre un `Contact` en el panel de organizadores, por `POST` con CSRF y confirmación; sin rutas JSON públicas y sin entradas nuevas en `docs/api/endpoints.md`.
- **Form Requests:** `EnableTwoFactorRequest`, `ConfirmTwoFactorRequest`, `ResetTwoFactorRequest`, `ExportPersonDataRequest`, `AnonymizePersonRequest` (con confirmación explícita y motivo).
- **Actions:** `RequireTwoFactor` (middleware y regla), `ResetTwoFactor`, `ExportPersonData` (archivo en disco privado con enlace temporal), `AnonymizePerson`, `ApplyRetentionPolicy` (programada), `ReapplyAnonymizations` (comando).
- **Permisos (nombres `entidad.accion`):** `personal_data.manage` (exportar y anonimizar), `two_factor.reset` (reinicio por otro administrador o por plataforma), `retention.manage`. **No** se reutiliza ningún nombre de permiso de otros planes.
- **Guardiana de inventario:** `PersonalDataInventoryTest` exige que cada columna con datos personales esté declarada en un registro de inventario y cubierta por `AnonymizePerson`; una columna nueva sin declarar hace fallar la prueba.
- **Correos nuevos en el catálogo (plan 008):** aviso de activación y de desactivación de 2FA y aviso del enlace de exportación; críticos, con predeterminadas es/en.
- **Cambios de base de datos (propuesta §6 de `docs/database-design.md`):**
  1. *Necesidad:* defensa en profundidad del aislamiento y cumplimiento de derechos de las personas; dueño: plataforma y privacidad.
  2. *Modelo:* migración de esquema que habilita RLS y crea políticas por cada tabla con `organization_id` fuera de la lista cerrada; rol de aplicación sin `BYPASSRLS`; columnas de 2FA en `users` (secreto cifrado, confirmado en, códigos de recuperación con hash); `retention_policies` (organización, categoría incluyendo `audit_log` y `backups`, plazo en días); `anonymization_log` (organización, hash del correo, fecha); `anonymized_at` en las tablas con datos personales. Ámbito: plataforma (2FA) y organización (el resto).
  3. *Integridad:* un esquema por rol de base: `app` sujeto a RLS y `migrator` propietario; pruebas de acceso permitido y denegado; sin disminuir restricciones existentes.
  4. *Cambio:* RLS en dos pasos («expandir y contraer»): primero políticas permisivas en `staging` con registro de rechazos; luego estrictas. Reversa: deshabilitar las políticas por tabla (descrita y probada en `staging`) sin perder datos.
  5. *Alternativa descartada:* una base de datos por organización (ADR-0003).
- **Variables de entorno nuevas:** credenciales del rol de aplicación y del rol de migraciones; sal de las huellas de anonimización; `[PENDIENTE: servicio de antivirus si se elige la opción (a) de DA-3]`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | `org_admin`, `organizer`, `reviewer` y `platform` deben activar 2FA. Exportar o anonimizar a una persona: `org_admin` y quien tenga `personal_data.manage`. Reiniciar 2FA: otro `org_admin` con `two_factor.reset` o, si no existe otro administrador, `platform` con verificación fuera de banda. Soporte de plataforma: solo con acceso concedido y auditado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `ContactPolicy` (`export`, `anonymize`), regla `TwoFactorRequired`; `tests/Feature/Privacy/`, `tests/Feature/Auth/` y `tests/Feature/Tenancy/Rls/` con permitido y denegado por rol |
| ¿Hay modelos nuevos con datos de una organización? | `RetentionPolicy` y `AnonymizationLog` con `BelongsToOrganization`; la 2FA vive en `users` (plataforma) |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No cruza eventos; exportar y anonimizar operan por organización |
| ¿Se guardan datos personales nuevos? | Secreto de 2FA (cifrado) y códigos de recuperación (con hash); huella no reversible de las personas anonimizadas; la exportación contiene datos personales y se entrega con enlace temporal; actualizar el inventario de `docs/privacidad.md` |
| ¿Se suben archivos? | No; la exportación se genera en disco privado con nombre generado y caducidad |
| ¿Hay dinero, pagos o webhooks? | No aplica; el webhook de entrega del plan 013 se ejercita bajo RLS en CA-014-14 |
| ¿Se envían correos? | Aviso de activación y de desactivación de 2FA, y aviso de enlace de exportación; registrados en el catálogo del plan 008, por cola |
| ¿Qué se registra en la auditoría? | Activación, reinicio y baja de 2FA, intentos fallidos, exportaciones y anonimizaciones (quién, a quién, cuándo), uso de `withoutOrganizationScope()`, ejecuciones de retención y restauraciones |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos y ADR de recortes de seguridad aprobados | S |
| F1 | 2FA obligatoria, códigos de recuperación y reinicio (H-014-1) | M |
| F2 | RLS en `staging`, contrato por tipo de ejecución, pruebas permitido y denegado, luego producción (H-014-2) | M |
| F3 | Ensayo de restauración, runbooks y reaplicación de anonimizaciones (H-014-3) | S |
| F4 | Inventario, exportar y anonimizar (H-014-4) | M |
| F5 | Retención programada (H-014-6) y decisión de escaneo (H-014-5) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-014-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-014-1 · 2FA obligatoria

- **CA-014-1:** *Dado* un usuario con rol `org_admin`, `organizer`, `reviewer` o `platform` sin 2FA, *cuando* inicia sesión, *entonces* es redirigido a configurarla y no accede a ninguna página de ningún panel hasta confirmarla.
- **CA-014-2:** *Dado* un usuario con 2FA activa, *cuando* ingresa un código TOTP inválido o repetido, *entonces* se rechaza, no se inicia sesión y se cuenta para el límite de intentos.
- **CA-014-3:** *Dado* un usuario que perdió su autenticador, *cuando* usa un código de recuperación válido, *entonces* accede y ese código queda inutilizable; un código ya usado se rechaza.
- **CA-014-4:** *Dado* un usuario que intenta desactivar su propia 2FA, *cuando* lo pide, *entonces* se deniega; *cuando* otro `org_admin` con `two_factor.reset` la reinicia, *entonces* se permite con auditoría y aviso por correo al titular.
- **CA-014-5:** *Dado* que no existe otro administrador en la organización, *cuando* el rol `platform` reinicia la 2FA de ese administrador y registra la verificación fuera de banda, *entonces* se permite con auditoría; sin ese registro, se deniega.

### H-014-2 · RLS de PostgreSQL

- **CA-014-6:** *Dado* el rol de la aplicación y una tabla con `organization_id`, *cuando* se consulta sin `app.organization_id` establecido, *entonces* no se devuelven filas.
  Manual: ejecutar la consulta en `psql` con el usuario de la aplicación y comprobar cero filas (antes CA-001-12).
- **CA-014-7:** *Dado* `app.organization_id` igual a A, *cuando* se consulta una tabla con tenant, *entonces* solo se devuelven filas de A aunque el scope de la aplicación se omita.
- **CA-014-8:** *Dado* `app.organization_id` igual a A, *cuando* se inserta o actualiza una fila con `organization_id` de B, *entonces* la base lo rechaza.
- **CA-014-9:** *Dado* una transacción terminada, *cuando* la conexión se reutiliza, *entonces* `app.organization_id` ya no tiene el valor anterior (se usa `SET LOCAL` dentro de la transacción de `CurrentOrganization::run()`).
- **CA-014-10:** *Dado* el rol de migraciones, *cuando* ejecuta una migración, *entonces* no depende de RLS; el rol de la aplicación no tiene `BYPASSRLS`.
- **CA-014-11:** *Dado* todas las tablas con `organization_id`, *cuando* corre la guardiana, *entonces* cada una tiene RLS habilitado y una política, salvo las de la lista cerrada de tablas de plataforma; una tabla nueva sin política y fuera de la lista hace fallar la prueba.
- **CA-014-12:** *Dado* un job en cola con `organization_id` en su carga, *cuando* se ejecuta con RLS activa, *entonces* ve y modifica solo datos de esa organización; un job sin contexto no devuelve filas ni modifica nada.
- **CA-014-13:** *Dado* dos organizaciones con propuestas pendientes de recordatorio y la limpieza de archivos del plan 007, *cuando* corre el planificador con RLS activa, *entonces* procesa cada organización dentro de su contexto y no mezcla datos entre ellas.
- **CA-014-14:** *Dado* un webhook de entrega con firma válida y metadatos de la organización A, *cuando* se procesa con RLS activa, *entonces* actualiza solo el envío de A; con metadatos que no corresponden a un envío de esa organización, se descarta sin cambios.
- **CA-014-15:** *Dado* un usuario con membresía, *cuando* inicia sesión, recupera su contraseña o cambia de organización con RLS activa, *entonces* el flujo funciona, porque las tablas de plataforma están fuera de RLS y su acceso lo gobiernan las Policies.

### H-014-3 · Restauración y runbooks

- **CA-014-16:** *Dado* un respaldo de producción reciente, *cuando* se restaura en `staging`, *entonces* la aplicación arranca, las migraciones coinciden y el conteo de propuestas coincide con el origen.
  Manual: registrar fecha, qué se restauró, resultado y tiempo en la tabla de `docs/operacion.md` §5.
- **CA-014-17:** *Dado* los casos listados en `docs/operacion.md` §9, *cuando* se revisa el documento, *entonces* cada caso (sitio caído, cola atrasada, correo que no llega, restauración, sospecha de fuga entre organizaciones, secreto expuesto) tiene su guía con pasos.
  Manual: leer cada guía con alguien que no la escribió y ejecutarla en `staging`.
- **CA-014-18:** *Dado* un respaldo restaurado, *cuando* se revisan los datos, *entonces* no se usan datos reales sin anonimizar en un entorno que no sea producción.
- **CA-014-19:** *Dado* un respaldo anterior a una anonimización, *cuando* se restaura y se ejecuta `app:reapply-anonymizations`, *entonces* las personas registradas en `anonymization_log` quedan anonimizadas de nuevo en los datos restaurados y el comando informa el total.

### H-014-4 · Exportar y anonimizar

- **CA-014-20:** *Dado* un `Contact` con propuestas, evaluaciones y envíos de correo, *cuando* un usuario con `personal_data.manage` de su organización exporta sus datos, *entonces* se genera un archivo con solo sus datos, en disco privado, con enlace temporal, y queda auditado.
- **CA-014-21:** *Dado* un `Contact`, *cuando* se anonimiza, *entonces* nombre, correo, teléfono, biografía y comentarios personales se sustituyen por valores no identificables, se conservan los agregados y se registra `anonymized_at`.
- **CA-014-22:** *Dado* una persona con datos de facturación sujetos a plazo fiscal, *cuando* se anonimiza, *entonces* esos datos se conservan y el resultado lo informa.
- **CA-014-23:** *Dado* un administrador de la organización A, *cuando* intenta exportar o anonimizar a un `Contact` de B, *entonces* la respuesta es 404.
- **CA-014-24:** *Dado* una anonimización pedida sin confirmación explícita, *cuando* se envía, *entonces* se rechaza y no cambia ningún dato.
- **CA-014-25:** *Dado* un contacto anonimizado, *cuando* un autor intenta usar su enlace de acceso, *entonces* se rechaza con un mensaje genérico.
- **CA-014-26:** *Dado* el inventario de datos personales, *cuando* corre `PersonalDataInventoryTest`, *entonces* toda columna con datos personales está declarada y cubierta por `AnonymizePerson`; una columna nueva sin declarar hace fallar la prueba.
- **CA-014-27:** *Dado* un contacto con registros en `activity_log`, `submission_participants` y respuestas `jsonb` de texto libre de sus propuestas, *cuando* se anonimiza, *entonces* su nombre y correo ya no aparecen en ninguna de esas fuentes (la auditoría conserva el hecho, con el actor y el sujeto sustituidos por un identificador no personal).
- **CA-014-28:** *Dado* un contacto con archivos subidos, *cuando* se anonimiza, *entonces* `submission_files.original_name` se sustituye, el archivo se elimina del bucket incluidas sus versiones y la fila conserva solo tipo, tamaño y fechas.
- **CA-014-29:** *Dado* un contacto con envíos de correo, evaluaciones con comentarios y tokens de acceso, *cuando* se anonimiza, *entonces* `mail_deliveries.recipient`, los comentarios de `reviews` y los tokens de acceso quedan anonimizados o invalidados, y la entrada se registra en `anonymization_log`.
- **CA-014-30:** *Dado* un usuario sin `personal_data.manage` ni rol `org_admin`, *cuando* intenta exportar o anonimizar, *entonces* la respuesta es 403 y no cambia ningún dato.

### H-014-5 · Escaneo de archivos

- **CA-014-31:** *Dado* las opciones de DA-3, *cuando* se toma la decisión, *entonces* queda registrada en un ADR corto con fecha, dueño y el punto de deuda correspondiente cerrado o reprogramado.
  Manual: revisar el ADR y `docs/sprints/deuda-tecnica.md`.
- **CA-014-32:** *Dado* que se elige escaneo asíncrono, *cuando* un archivo está pendiente o resulta infectado, *entonces* no se puede descargar y el organizador ve su estado.

### H-014-6 · Retención y anonimización programadas

- **CA-014-33:** *Dado* una política de retención de N días para una categoría, *cuando* corre el trabajo programado (con el contexto de cada organización), *entonces* se anonimizan o eliminan los datos vencidos de esa categoría y de ninguna otra.
- **CA-014-34:** *Dado* un fallo a mitad de la ejecución, *cuando* el trabajo se reintenta, *entonces* es idempotente: no repite ni deja datos a medias.
- **CA-014-35:** *Dado* un usuario con `retention.manage` en A, *cuando* configura plazos, *entonces* solo afectan a A; cada ejecución se audita con totales, sin datos personales.
- **CA-014-36:** *Dado* una política de retención de la categoría `audit_log`, *cuando* corre el trabajo, *entonces* los registros de `activity_log` vencidos se anonimizan o eliminan, y los que corresponden a acciones sensibles no vencidas se conservan.
- **CA-014-37:** *Dado* la política de respaldos de la categoría `backups`, *cuando* se revisa la configuración del proveedor, *entonces* ningún respaldo supera la retención declarada.
  Manual: revisar en Railway y en el bucket de respaldos la retención configurada y anotar la fecha de la revisión en `docs/operacion.md` §5.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| RLS bloquea consultas legítimas (jobs, planificador, webhook, login) | Contrato de contexto por tipo de ejecución (DA-2), lista cerrada de tablas de plataforma y CA-014-12 a 15; despliegue gradual con registro en `staging`; reversa descrita |
| Pérdida de acceso por 2FA | Códigos de recuperación, reinicio por otro administrador o por plataforma con verificación fuera de banda, todo auditado (CA-014-3 a 5) |
| Evaluadores externos con acceso antes de la 2FA | H-011-3 y H-011-8 dependen de H-014-1 (CA-011-34) |
| Anonimizar datos que deben conservarse | Excepción fiscal (CA-014-22) y confirmación explícita (CA-014-24) |
| Datos personales que sobreviven en el log, en archivos o en un respaldo | Inventario con guardiana (CA-014-26), cobertura de cada fuente (CA-014-27 a 29), reaplicación tras restaurar (CA-014-19) y retención de respaldos (CA-014-37) |
| Restauración que nunca se probó | Ensayo con evidencia en `docs/operacion.md` (CA-014-16) |
| Retraso por el receso de fin de año o un R0 tardío | Orden de prioridad declarado en §1: 2FA primero (bloquea evaluadores), luego RLS |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
