# feat: endurecimiento y privacidad (2FA, RLS, restauración y derechos de las personas)

> Plan de la épica E-14 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 014: todos sus criterios son `CA-014-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Aprobado (Antonio, 2026-11-23: especificacion y propuesta de datos de 2FA; F0 y F1 construidas. Antonio, 2026-11-24: alcance ampliado a F2 a F5 en lo que es codigo y documentacion: RLS con su contrato de contexto, runbooks y reaplicacion de anonimizaciones, exportar y anonimizar, retencion programada y un ADR de escaneo con las dos opciones medidas sin decidir. Quedan para una persona: la restauracion real en staging, los plazos de retencion y de respaldos, la decision del escaneo y activar RLS en staging y produccion)
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

### 4.1 Contrato de la 2FA para otros planes (implementado en F1)

Se construyó sobre la autenticación multifactor de Filament 5 (`filament/filament` v5.11, con `pragmarx/google2fa` ya en `composer.lock`): sin dependencias nuevas. Lo que los demás planes consultan:

| Qué | Dónde | Significado |
|---|---|---|
| `TwoFactorRequirement::isEnforced(): bool` | `app/Support/Auth/TwoFactorRequirement.php` | La exigencia está **activa en este entorno** (`config('two_factor.required')`, variable `TWO_FACTOR_REQUIRED`, encendida por defecto). **El plan 011 (H-011-8, CA-011-34) rechaza invitar a evaluadores externos si devuelve `false`.** |
| `User::requiresTwoFactor(): bool` | `app/Models/User.php` | A la persona se le exige: usuario de plataforma o con rol `org_admin`, `organizer` o `reviewer` en alguna organización (se consulta sin filtrar por organización en contexto). Incluye `reviewer` aunque ese rol aún no tenga panel. |
| `User::hasConfirmedTwoFactor(): bool` | idem | Tiene secreto y `two_factor_confirmed_at`: configuró el segundo factor y lo confirmó con un código válido. |
| `TwoFactorRequirement::isSatisfiedBy(User): bool` | idem | Exigencia apagada, o a la persona no se le exige, o ya la confirmó. Es la comprobación para decidir acceso a datos de autores desde fuera de los paneles de Filament. |
| `EnsureTwoFactorConfirmed` | `app/Http/Middleware` | Middleware de los paneles `admin` y `platform` (se enchufa como `multiFactorAuthenticationRequiredMiddlewareName`): quien no cumple `isSatisfiedBy` va a la página de configuración y no ve ninguna página del panel. Un panel nuevo (p. ej. el del evaluador, plan 011 F4) debe declarar `->multiFactorAuthentication([TotpAuthentication::configured()], isRequired: true)` y ese middleware. |

Decisiones de F1 que otros planes deben conocer:

- **Quién cumple, no cuándo:** la exigencia se decide por petición con `TwoFactorRequirement`, no al registrar rutas (Filament evalúa «¿es obligatoria?» antes de que haya usuario), así que apagar `TWO_FACTOR_REQUIRED` no exige recachear rutas. Con la bandera apagada **nada la exige**: solo para pruebas y desarrollo (`phpunit.xml` la apaga; las pruebas que la ejercitan la encienden con `config(['two_factor.required' => true])`).
- **No se puede desactivar por uno mismo:** `User::saveAppAuthenticationSecret(null)` lanza `TwoFactorCannotBeDisabled` y el panel no ofrece «Desactivar». Quitarla es solo `User::clearTwoFactor()`, usado por `ResetTwoFactor` y `ResetTwoFactorByPlatform`.
- **Reinicio por otro administrador** (`ResetTwoFactor`, acción «Reiniciar verificación en dos pasos» en el equipo): permiso `two_factor.reset` (solo `org_admin`), destinatario miembro activo de la misma organización, nunca la propia cuenta; `MembershipPolicy::resetTwoFactor`.
- **Reinicio por plataforma** (`ResetTwoFactorByPlatform`, acción en la ficha del usuario del panel de plataforma): `MembershipPolicy::resetTwoFactorAsPlatform` solo lo permite si **ninguna organización activa del destinatario tiene otro administrador activo** (un usuario de plataforma sin organizaciones cumple) y nunca sobre la propia cuenta; además exige la **verificación fuera de banda** (`ResetTwoFactorRequest`: canal `phone_call`, `message` o `in_person` —nunca el correo de la cuenta— y una nota de 10 a 500 caracteres) que se guarda en la auditoría. Sin ella, `ValidationException` y nada cambia.
- **Auditoría y avisos:** evento `two_factor_reset` (causante, sujeto, `by`, y la verificación en el caso de plataforma; sin secretos) y correos críticos `auth.two_factor_reset` y `auth.two_factor_enabled` (catálogo del plan 008, plantillas es/en, por cola y tras confirmar la transacción).
- **Intentos:** cada envío de código pasa por el límite del inicio de sesión (`AccessThrottle`, 5 por 15 min por correo e IP; el envío de la contraseña cuenta como uno), además del límite propio de Filament para el desafío. Un código TOTP no se acepta dos veces (Filament guarda el último paso aceptado en caché). 8 códigos de recuperación por persona, de un solo uso, guardados con hash y cifrados; no se regeneran desde el panel (no hay pantalla de perfil).
- **Secretos:** el secreto TOTP va cifrado (`encrypted`), los códigos de recuperación con hash dentro de un arreglo cifrado (`encrypted:array`); ambos están ocultos (`hidden`) y nunca se registran.
- **Límite conocido:** el middleware actúa al abrir una página. Una pestaña ya abierta de alguien a quien se le reinició la 2FA puede seguir enviando acciones de Livewire hasta que navegue (deuda DT-18).

### 4.2 Paso de despliegue de la 2FA (lo ejecuta una persona)

1. Las migraciones `2026_11_23_140000_add_two_factor_to_users_table` (esquema, aditiva y reversible) y `2026_11_23_140100_sync_roles_for_two_factor_reset_permission` (datos: da `two_factor.reset` a los `org_admin` existentes) las aplica el humano, primero en `staging`.
2. **Efecto al aplicarlas con `TWO_FACTOR_REQUIRED` encendido (el valor por defecto):** todo usuario existente queda sin 2FA y se le fuerza a configurarla en su siguiente apertura de página del panel (incluidas las sesiones abiertas). Planificarlo: avisar antes a cada administrador y organizador, y a quien use el panel de plataforma.
3. **Cuenta de recuperación (CA-014-5):** antes de encender la exigencia en producción, dejar al menos **dos administradores por organización** (para que uno reinicie al otro) y **dos usuarios de plataforma** (se crean con `php artisan app:create-platform-user`; se reinician entre sí con verificación fuera de banda). Si un administrador único pierde su autenticador y sus códigos, lo reinicia un usuario de plataforma desde `/platform` → Usuarios → la ficha → «Reiniciar verificación en dos pasos», anotando la verificación fuera de banda (llamada o mensaje a un contacto registrado previamente). Si pierde también un usuario de plataforma, lo reinicia el otro. Registrar quién puede hacerlo y los contactos fuera de banda en `docs/operacion.md` §9 al planificar.
4. `PLATFORM_PANEL_ENABLED` puede activarse en staging y producción **solo cuando** `TWO_FACTOR_REQUIRED` esté encendido y las cuentas de plataforma ya tengan su 2FA (`docs/operacion.md`, `docs/seguridad.md` §2).
5. Reversa: `php artisan migrate:rollback --step=2` quita las columnas (se pierden los secretos: todos tendrían que reconfigurarla) y la matriz vuelve a sembrarse sin el permiso; la forma rápida de «apagar» la exigencia sin migrar es `TWO_FACTOR_REQUIRED=false` (reabre el acceso con solo contraseña: decisión de Antonio y queda registrada).

### 4.3 Contrato de privacidad (F4 construida el 2026-11-24)

Decisiones de implementación (el agente de privacidad las toma dentro del alcance aprobado; Gabriel o Antonio las confirman o las cambian):

| DA | Decisión |
|---|---|
| DA-6 | **Quién es «una persona».** Se localiza por el correo normalizado (minúsculas, sin espacios) y por su vínculo con el contacto, SIEMPRE dentro de la organización en contexto: en `contacts`, `submission_participants`, `speakers` (con borrados lógicos), la membresía (`users`) y los envíos de correo por la huella `HMAC(app.key)` del correo. La misma persona en otra organización no se toca (CA-014-23). Sin datos en la organización: 404 (`PersonDataNotFound`); ya anonimizada (huella en `anonymization_log`): éxito sin cambios (`already_anonymized`). |
| DA-7 | **Qué se exporta.** Sus datos y los que ella aportó, en un JSON; nunca secretos, hashes, rutas de disco, datos de coautores ni el motivo interno de una decisión (no se le envía al autor). Los comentarios que los evaluadores escribieron sobre sus propuestas se incluyen **sin la identidad del evaluador** (a confirmar por el responsable: es el criterio más protector del evaluador que permite el derecho de acceso). |
| DA-8 | **Cuenta de acceso.** Si pertenece solo a esta organización, se anonimiza entera; si pertenece también a otra, se conserva la cuenta, se desactiva su membresía aquí y se anonimiza lo suyo en esta organización (`account_kept_other_organizations`). Se rechaza todo, sin cambios, para un usuario de plataforma, para la propia cuenta de quien lo pide y para el último administrador activo (`LastOrganizationAdmin`). |
| DA-9 | **Archivos.** Se borran los de las propuestas donde la persona es participante **principal** (quien las subió) y la fotografía de sus fichas de ponente; el disco se borra ANTES que la base (si falla, la base no cambia y se repite). Las versiones del bucket son deuda (DT-50). |
| DA-10 | **Texto libre.** Se le quita el nombre completo y el correo (título y respuestas de sus propuestas, comentarios de evaluación sobre ella, auditoría); el comentario de la decisión y el motivo interno se sustituyen enteros. Límite: DT-53. |
| DA-11 | **Motivo.** Lista cerrada (`PersonDataRequestReason`): un texto libre acabaría en la auditoría con datos personales. La confirmación de la anonimización es volver a escribir el correo. |
| DA-12 | **Exportación.** JSON en el disco privado (`organizations/{id}/exports/person-{uuid}.json`, que la tarea `submission-exports:purge` ya borra a las 24 horas) y enlace firmado de 10 minutos que solo sirve a quien lo pidió, igual que la de propuestas (plan 009). Ruta nueva `GET admin/{organization:slug}/person-data-exports/{export}` (descarga del panel, no una ruta JSON pública: sin entrada en `docs/api/endpoints.md`). |

| Pieza | Contrato |
|---|---|
| `ExportPersonData::handle(User $actor, array $data): PersonDataExport` | `data`: `email`, `reason`. 403 sin `personal_data.manage`; `ValidationException` (correo o motivo); `PersonDataNotFound` (404). Escribe el archivo, audita `person_data_exported` (quién, motivo, totales) y devuelve `{id, url, expiresAt, counts}` |
| `DownloadPersonDataExport::handle(User $actor, string $exportId): array` | 403 sin permiso; 404 si la exportación no es de `$actor`, de otra organización o tiene más de 24 horas |
| `AnonymizePerson::handle(User $actor, array $data): AnonymizationResult` | `data`: `email`, `reason`, `confirmation` (el correo otra vez). 403 → validación → 404. Errores: `PersonCannotBeAnonymized` (`own_account`, `platform_account`), `LastOrganizationAdmin`, `PersonFileDeletionFailed`, `AnonymizationSaltMissing`. Devuelve `{counts, retained, notes}` y audita `person_anonymized` (sin datos personales) |
| `ErasePersonRecords::handle(PersonRecords, ?User, ?Closure): AnonymizationResult` | Motor compartido con la reaplicación tras restaurar (F3); no autoriza ni audita |
| `PersonalDataInventory` + `PersonalDataInventoryTest` | CA-014-26: modelos cubiertos o exentos, columnas clasificadas y columnas `replaced` que de verdad cambian |
| Pantalla «Datos de una persona» (`App\Filament\Pages\Privacy\PersonalData`) | Solo presentación; requiere `personal_data.manage` |
| Variable `ANONYMIZATION_SALT` | Sal de la huella (`config/privacy.php`); sin ella no se anonimiza. En `.env.example` solo con valor ficticio |
| Permiso `retention.manage` | Solo `org_admin`; migración de datos `2026_11_27_300200` |

**F3 (reaplicación tras restaurar, construida el 2026-11-24).** El ensayo real de restauración en `staging` lo hace una persona (lista de verificación en `docs/operacion.md` §5.2); lo construido es el comando, el procedimiento y los runbooks de §9 de ese documento.

| DA | Decisión |
|---|---|
| DA-13 | **El registro viaja con la restauración.** `anonymization_log` está en la misma base que se restaura, así que un respaldo anterior a una anonimización también lo trae incompleto. Por eso, antes de restaurar se guarda con `app:export-anonymization-log` y después se carga con `app:reapply-anonymizations --from-file` (solo huellas no reversibles, fechas e identificadores de organización; sin correos). Requiere que `ANONYMIZATION_SALT` sea la misma antes y después. |
| DA-14 | **Qué se reaplica.** Para cada organización, los contactos, participantes, ponentes y miembros aún sin anonimizar cuya huella (HMAC con la sal y la organización) está en el registro, con el mismo motor que la anonimización a petición. Los casos que esa rechaza (último administrador, cuenta de plataforma) no se fuerzan: se informan como omitidos y el comando termina con error, para que no se abra el servicio con datos que deberían estar anonimizados. |

| Pieza | Contrato |
|---|---|
| `ReapplyAnonymizations::handle(Organization $organization, bool $simulate = false): ReapplyReport` | Por organización con `CurrentOrganization::run()`; `ReapplyReport{registered, found, reapplied, skipped[motivo => n], failed}`; idempotente; audita `anonymizations_reapplied` con los totales (sin datos personales) salvo en simulación |
| `app:reapply-anonymizations {--organization=} {--from-file=} {--dry-run}` | Imprime por organización y el total («Personas reaplicadas: N de M con datos»); código de salida distinto de 0 si hay omitidas, con error, un archivo ilegible o una organización inexistente; `--dry-run` no se combina con `--from-file` |
| `app:export-anonymization-log {path}` / `ExportAnonymizationLog` / `ImportAnonymizationLog` | Guarda y recarga las huellas de todas las organizaciones (JSON `{version: 1, entries[]}`), todo o nada, idempotente; el destino nunca puede estar dentro de `public/` y el archivo se crea con permisos `0600` |

**F5 (retención programada y escaneo, construida el 2026-11-24).**

| DA | Decisión |
|---|---|
| DA-15 | **Sin plazos por defecto.** `[PENDIENTE: plazos de retención por categoría y quién los aprueba]` sigue abierto: la configuración nace vacía, la tarea no actúa sobre una categoría sin plazo y una organización sin plazos no hace nada ni se audita (denegar por defecto). Los plazos los fija cada organización (`retention.manage`, solo `org_admin`). |
| DA-16 | **Categorías y qué hace cada una** (`RetentionCategory`): `unaccepted_submissions` (rechazadas o retiradas desde hace más del plazo, contado desde la decisión o el retiro y, si no consta, desde el envío: se anonimiza a sus autores, se borran sus archivos, se invalidan los enlaces y se sustituyen los comentarios de la decisión; el contacto se anonimiza solo si ya no tiene otros datos vivos; se conserva el contenido de la propuesta); `reviews` (se sustituye el texto de los comentarios; se conservan las calificaciones); `mail_dispatches` (se rompe el vínculo con el destinatario); `audit_log` (se eliminan los registros de la organización más antiguos que el plazo); `backups` (solo declarativa: ningún trabajo borra respaldos, DA-5). Quedan fuera las propuestas aceptadas, los ponentes y los contactos sin propuestas (DT-53). |
| DA-17 | **Reintentos.** Cada propuesta y cada bloque de evaluaciones van en su transacción; el disco se borra antes que la base; lo ya anonimizado no se vuelve a seleccionar. Un fallo de una propuesta se reporta, no detiene a las demás y se reintenta al día siguiente; el comando termina con error. |
| DA-18 | **Escaneo de archivos (H-014-5): sin decisión.** Se escribió el ADR-0011 con las dos opciones y una recomendación; la decisión es de Antonio y no se implementó ningún escaneo. CA-014-32 queda pendiente de esa decisión (aplica solo a la opción A). |

| Pieza | Contrato |
|---|---|
| `ApplyRetentionPolicy::handle(Organization $organization): RetentionReport` | Con `CurrentOrganization::run()`; `RetentionReport{counts por categoría con plazo, failed[]}`; no hace nada y no audita sin plazos; audita `retention_applied` con los totales; idempotente |
| `retention:apply {--organization=}` | Programado a diario a las 04:00 (`withoutOverlapping`); imprime por organización; código de salida distinto de 0 si una categoría falla |
| `SetRetentionPolicies::handle(User $actor, array $data): array` | `data`: categoría => días (1 a 36500) o vacío para quitar el plazo; solo las categorías presentes cambian; 403 sin `retention.manage`; `ValidationException`; audita `retention_policies_updated` |
| Pantalla «Retención de datos» (`App\Filament\Pages\Privacy\RetentionSettings`) | Solo presentación; campos vacíos = sin plazo |

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
| F2 | `c7c5199` (RLS, CA-014-6 a 13 y 15) y el commit `docs(plans)` siguiente (operacion §10, seguridad, deuda DT-46 a 49) | 2026-11-24 | **Criterios:** CA-014-6 a CA-014-13 y CA-014-15 con prueba en `tests/Feature/Tenancy/Rls/` (4 archivos, 27 pruebas); **CA-014-14 sin prueba**: el webhook de entrega del plan 013 no existe (DT-49, `Manual:` en el criterio). **Validaciones (APP_ENV=testing, base `testing_014k`):** Pint PASS 940 archivos; Larastan `[OK] No errors`; `composer check:criteria` 0 errores (12 avisos preexistentes del plan 002); suite completa `tests/Arch tests/Feature tests/Unit`: 2651 passed, 11 failed, **los 11 ajenos conocidos** (exportacion CA-009-11 a 19 y 2 de `PrivacyNoticePanelTest`). Ademas, con `RLS_TEST_MODE=1` (toda consulta con organizacion en contexto corre con un rol sin BYPASSRLS y no propietario) `tests/Feature`: 2556 passed, 18 failed = los 11 ajenos + 7 pruebas que leen filas de OTRA organizacion desde el propio codigo de prueba con un contexto activo (`withoutGlobalScopes()` en `ActivityPanelTest`, `ProgramCategoryPanelTest`, `SpeakerPanelTest`, `SpeakerActionsTest`, `AccessLinkTest`, `AuthorSessionTest`/`AuthorFixtures`) o crean un disparador (DDL); ningun flujo de la aplicacion (paneles, formulario publico, autor, jobs, comandos) fallo. La reversa (`migrate:rollback --path` y el SQL de operacion §10.5) se ejecuto en `testing_014k` sin perder datos. **No ejecutado:** activacion en staging y produccion (accion humana, operacion §10); recorrido en navegador; CA-014-14. **Desviaciones:** (1) contexto con `set_config` de sesion sincronizado antes de cada consulta (`DatabaseTenantContext`) y no `SET LOCAL` en una transaccion por `run()` (el middleware de Filament no puede envolver la peticion de Livewire); equivalente en CA-014-9 y probado con conexion reutilizada, reconectada y rollback; (2) RLS sin `FORCE`, asi el rol de migraciones (propietario) no depende de ella y el efecto lo da el rol de la conexion; (3) politica extra `platform_read` (solo SELECT) abierta por `OrganizationScopeBypass`; (4) lista cerrada de 5 tablas (organization_user, roles, model_has_roles, model_has_permissions, activity_log), justificadas en `RowLevelSecurity::PLATFORM_TABLES`; (5) conexion `pgsql_migrator` y variables `DB_MIGRATOR_*`; (6) comando `app:rls-status` y `RowLevelSecurity::enableMissing()` no estaban en el plan; (7) la prueba de UPDATE a otra organizacion la rechaza antes el disparador `prevent_organization_id_change`. Deuda: DT-46 a DT-49. |
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
  Manual: pendiente de que exista el webhook de entrega del plan 013 (no está construido); al hacerlo se ejercita con el rol de aplicación de `Tests\Support\AppDatabaseRole`. Registrado en DT-49.
- **CA-014-15:** *Dado* un usuario con membresía, *cuando* inicia sesión, recupera su contraseña o cambia de organización con RLS activa, *entonces* el flujo funciona, porque las tablas de plataforma están fuera de RLS y su acceso lo gobiernan las Policies.

### H-014-3 · Restauración y runbooks

- **CA-014-16:** *Dado* un respaldo de producción reciente, *cuando* se restaura en `staging`, *entonces* la aplicación arranca, las migraciones coinciden y el conteo de propuestas coincide con el origen.
  Manual: registrar fecha, qué se restauró, resultado y tiempo en la tabla de `docs/operacion.md` §5.
- **CA-014-17:** *Dado* los casos listados en `docs/operacion.md` §9, *cuando* se revisa el documento, *entonces* cada caso (sitio caído, cola atrasada, correo que no llega, restauración, sospecha de fuga entre organizaciones, secreto expuesto) tiene su guía con pasos.
  Manual: leer cada guía con alguien que no la escribió y ejecutarla en `staging`.
- **CA-014-18:** *Dado* un respaldo restaurado, *cuando* se revisan los datos, *entonces* no se usan datos reales sin anonimizar en un entorno que no sea producción.
  Manual: en cada ensayo de restauración, comprobar con la lista de `docs/operacion.md` §5.2 que `staging` queda anonimizado antes de usarse (hoy sin procedimiento automático: DT-52).
- **CA-014-19:** *Dado* un respaldo anterior a una anonimización, *cuando* se restaura y se ejecuta `app:reapply-anonymizations`, *entonces* las personas registradas en `anonymization_log` quedan anonimizadas de nuevo en los datos restaurados y el comando informa el total.

### H-014-4 · Exportar y anonimizar

- **CA-014-20:** *Dado* un `Contact` con propuestas, evaluaciones y envíos de correo, *cuando* un usuario con `personal_data.manage` de su organización exporta sus datos, *entonces* se genera un archivo con solo sus datos, en disco privado, con enlace temporal, y queda auditado.
- **CA-014-21:** *Dado* un `Contact`, *cuando* se anonimiza, *entonces* nombre, correo, teléfono, biografía y comentarios personales se sustituyen por valores no identificables, se conservan los agregados y se registra `anonymized_at`.
- **CA-014-22:** *Dado* una persona con datos de facturación sujetos a plazo fiscal, *cuando* se anonimiza, *entonces* esos datos se conservan y el resultado lo informa.
  Nota F4: R1 no guarda datos de facturación (el módulo de pagos es R3, ADR-0007). La prueba cubre el contrato: el resultado trae `retained` (vacío hoy). Al construir los pagos, sus datos fiscales se añaden a `retained` y se prueba con datos reales.
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
  Manual: solo aplica si Antonio elige la opción A del ADR-0011; mientras no haya decisión no existe un escaneo que probar (DA-18). Si se elige, este criterio pasa a tener prueba.

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
| F0 | `8722998` (aprobación, Antonio); propuesta de datos en `docs/database-design.md` §8.2 (commit `docs(plans)` de F1) | 2026-11-23 | Especificación y propuesta de datos de 2FA aprobadas por Antonio el 2026-11-23 (alcance F0 y F1). Sin código. §8.2 materializa la propuesta de §4 para las columnas de `users`; el resto de la propuesta (RLS, retención, anonimización) sigue sin aprobar. |
| F1 | `ef19394` (2FA obligatoria, CA-014-1 a 3), `7707f4a` (reinicio, CA-014-4 y 5), `4543248` (corrección de guardiana) y el commit `docs(plans)` siguiente | 2026-11-23 | **Criterios:** CA-014-1 a CA-014-5 con prueba (`tests/Feature/Auth/TwoFactorRequiredTest.php`, 16 pruebas; `TwoFactorResetTest.php`, 15). **Validaciones (APP_ENV=testing, base `testing_014`):** Pint `--test` PASS 781 archivos; Larastan `[OK] No errors`; `composer check:criteria` 0 errores (12 avisos preexistentes del plan 002); `tests/Arch`, `Feature/Auth`, `Feature/Tenancy`, `Feature/Team` y `Feature/Platform` tras el último cambio: 605 passed (1900 assertions); suite completa antes de la corrección de `4543248`: 2246 passed, 12 failed, de los que 1 era propio (`TenantBypassTest`, `DB::table` en `User`, corregido en `4543248` y ahora en verde) y 11 ajenos: exportación CA-009-11 a 19 (`SubmissionExportTest`, `SubmissionListTest`, falta `maatwebsite/excel` en el `vendor/` del entorno) y 2 de `PrivacyNoticePanelTest` (CA-003-11 y CA-003-14), que **también fallan sobre `staging` (`dab1eb3`)** y no se tocaron. La suite completa no se volvió a correr entera tras `4543248` (1004 s). **No ejecutado:** verificación en navegador a ~375 y ~1280 px del flujo de configuración de 2FA (la página de configuración, el modal con el código QR y los códigos de recuperación son los de Filament); el alta real con una app autenticadora y el aviso por correo (se probaron con `Notification::fake()` y el secreto/códigos sembrados). **Desviaciones:** (1) la bandera `TWO_FACTOR_REQUIRED` es nueva (no estaba en el plan) y `phpunit.xml` la apaga, porque sin ella las ~2200 pruebas existentes de paneles quedarían redirigidas al alta de 2FA; las pruebas de 2FA la encienden por prueba; (2) las pruebas se escribieron junto con el código y se verificaron en verde, sin una corrida previa en rojo documentada; (3) el aviso de «desactivación» del §4 se materializó como el aviso de **reinicio** (`auth.two_factor_reset`), porque nadie desactiva la suya; (4) `OrganizationAdmins` ganó `anotherAdminExists`/`anotherAdminExistsInAnyOrganizationOf` y `MembershipPolicy` dos habilidades nuevas (`resetTwoFactor`, `resetTwoFactorAsPlatform`), sin cambiar firmas existentes; (5) no se creó `EnableTwoFactorRequest`/`ConfirmTwoFactorRequest`: la configuración la valida Filament (código TOTP y contraseña, con su propio límite), solo existe `ResetTwoFactorRequest`; (6) deuda nueva DT-18 y avance en DT-02. Contrato para otros planes y paso de despliegue: §4.1 y §4.2. |
| F4 | `443e4f9` (exportar y anonimizar), más el commit `docs(plans)` de este registro | 2026-11-24 | **Criterios:** CA-014-20 a CA-014-30 con prueba (`tests/Feature/Privacy/`: `AnonymizePersonTest` 28, `ExportPersonDataTest` 10, `PersonalDataInventoryTest` 52 —la guardiana de CA-014-26—, `PersonalDataPanelTest` 8). CA-014-22 cubre el contrato (`retained`, vacío hoy: no hay datos de facturación en R1). **Validaciones (APP_ENV=testing, base `testing_014l`, wrapper `d014l.sh`):** Pint `--test` PASS 977 archivos; Larastan `[OK] No errors`; `composer check:criteria` 0 errores (12 avisos preexistentes del plan 002); guardianas y pruebas de privacidad (`Tests.Feature.Privacy`, `LangParityTest`, `ArchTest`, `TenantBypassTest`, `ModelsAreScopedTest`, `OrganizationIdImmutableTest`, `TenantIsolationTest`, `RolesMatrixTest`, `PlatformPanelTest`) tras F5: 514 passed y 2 failed ajenos (`PrivacyNoticePanelTest`, CA-003-11 y 14); **suite completa** (`tests/Arch tests/Feature tests/Unit`, 1586 s) sobre `eaafdc2`: 2772 passed y 12 failed, de los que 11 son los ajenos conocidos (exportación CA-009-11 a 19 y los 2 de `PrivacyNoticePanelTest`, que fallan igual en `staging`) y 1 era propio: `NavigationOrderTest` CA-020-1 esperaba el menú del administrador sin las dos pantallas de privacidad; se actualizó en el commit de este registro (9 passed). **No ejecutado:** verificación en navegador a ~375 y ~1280 px de las dos pantallas nuevas (Livewire y notificaciones probados sin navegador); descarga contra un bucket S3 real (las pruebas usan un disco falso y la URL firmada de la propia aplicación); aplicar las migraciones fuera de `testing_014l` (las aplica una persona: §4.2 del plan y `docs/database-design.md` §8.3). **Desviaciones:** (1) pruebas escritas junto con el código y verificadas en verde, sin una corrida previa en rojo documentada (como en F1); (2) la persona se localiza por correo en todos los módulos y no por un `Contact` concreto: la Policy es `ContactPolicy` contra la clase y el «404 del contacto de B» (CA-014-23) es que la persona no existe en la organización en contexto; (3) ruta nueva de descarga `person-data-exports.download` (panel, firmada; no JSON público); (4) el aviso por correo del enlace de exportación NO se creó (`NotificationCatalog` era de otro carril): DT-51; (5) `DownloadSubmissionFile` ganó una condición para no entregar archivos anonimizados y `NavigationOrderTest` se actualizó (fuera del módulo, sincronizados); (6) deuda nueva DT-50 a DT-53; (7) decisiones de implementación DA-6 a DA-12 (§4.3) a confirmar. |
| F3 | `d4a80c9` (reaplicación y runbooks), más el commit `docs(plans)` de este registro | 2026-11-24 | **Criterios:** CA-014-19 con prueba (`ReapplyAnonymizationsTest`, 13). CA-014-16, 17 y 18 son manuales y **no se ejecutaron** (el ensayo real de restauración en `staging` lo hace una persona: lista de verificación en `docs/operacion.md` §5.2); CA-014-17 tiene las guías escritas (§9.1 a 9.7) pero falta que alguien que no las escribió las lea y las ejecute en `staging`. **Validaciones:** las de F4 (Pint, Larastan, `check:criteria`, suite completa); `ReapplyAnonymizationsTest` 13 passed. **No ejecutado:** restauración real de un respaldo, ensayo de los runbooks, `app:reapply-anonymizations` contra una base de `staging`. **Desviaciones:** (1) `anonymization_log` está en la base que se restaura, así que se añadieron `app:export-anonymization-log` y `--from-file` (DA-13), que el plan no listaba; (2) la guía de «sitio caído», «cola atrasada», «correo que no llega», «fuga entre organizaciones» y «secreto expuesto» (CA-014-17) se escribieron con lo que el repositorio permite afirmar y `[RELLENAR]` donde depende del proveedor; el webhook de pagos queda como guía para R3; (3) anonimizar en bloque un respaldo para `staging` no existe (DT-52). |
| F5 | `eaafdc2` (retención y ADR-0011), más el commit `docs(plans)` de este registro | 2026-11-24 | **Criterios:** CA-014-33 a CA-014-36 con prueba (`RetentionPolicyTest`, 24). CA-014-31 (ADR) y CA-014-37 (revisión del proveedor) son manuales: el ADR-0011 está escrito en estado **Propuesta** con la decisión `[PENDIENTE: Antonio]` y la revisión de la retención de respaldos **no se hizo**. CA-014-32 solo aplica si se elige escaneo (DA-18). **Validaciones:** las de F4 (todas pasan sobre `eaafdc2`); `RetentionPolicyTest` 24 passed. **No ejecutado:** una ejecución de `retention:apply` contra datos reales o de `staging`; verificación en navegador de «Retención de datos». **Desviaciones:** (1) sin plazos por defecto (`[PENDIENTE: plazos]`: la configuración nace vacía); (2) la categoría `backups` es solo declarativa; (3) la retención no cubre ponentes, contactos sin propuestas ni propuestas aceptadas (DT-53); (4) ADR-0011: numeración tomada como la siguiente libre en las tres ramas hermanas al escribirlo, a revisar al integrar. |
