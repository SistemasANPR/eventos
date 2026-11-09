# feat: endurecimiento y privacidad (2FA, RLS, restauración y derechos de las personas)

> Plan de la épica E-14 (R1). Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 014: todos sus criterios son `CA-014-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** cierra RC-1 (RLS, P1), RC-2 (2FA, P1) y RC-3 (escaneo de archivos, P2) de `docs/plan-de-desarrollo.md` §3.4; estos puntos se registran en `docs/sprints/deuda-tecnica.md` al aprobar el ADR de recortes de seguridad

## 1. Objetivo y alcance

Cerrar los recortes de seguridad aceptados para lanzar R0 (fecha límite 18-dic-2026): 2FA obligatoria para administradores y roles de plataforma, RLS de PostgreSQL como segunda barrera de aislamiento, restauración de respaldos ensayada con runbooks, y las herramientas de privacidad (exportar, anonimizar, retener) que `docs/privacidad.md` exige. La 2FA es además condición para dar acceso a evaluadores externos (plan 011). Dueño de los recortes: Antonio.

**Dentro:** 2FA; RLS con `SET LOCAL app.organization_id` y pruebas de acceso permitido y denegado (incluye el antiguo CA-001-12, ahora CA-014-n); ensayo de restauración y runbooks en `docs/operacion.md`; exportación y anonimización de los datos de una persona; decisión sobre el escaneo de archivos; retención y anonimización programadas.

**Fuera:** SSO o inicio de sesión social; pagos y su conciliación (R3); marca blanca.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-014-1 | Como administrador, quiero verificación en dos pasos obligatoria para administradores y roles de plataforma, para proteger las cuentas con más acceso | M | H-001-4 |
| H-014-2 | Como líder, quiero RLS de PostgreSQL como segunda barrera de aislamiento, para protegerme de un fallo del scope | M | H-001-1 |
| H-014-3 | Como líder, quiero ensayar la restauración de un respaldo y documentar los runbooks, para recuperar el servicio | S | H-010-3 |
| H-014-4 | Como organización, quiero exportar y anonimizar los datos de una persona, para atender sus derechos | M | H-006-1 |
| H-014-5 | Como líder, quiero decidir sobre el escaneo de archivos, para cerrar RC-3 | S | H-007-1 |
| H-014-6 | Como organización, quiero retención y anonimización programadas, para no guardar datos de más | M | H-014-4 |

## 2. Punto de partida

Estado esperado al iniciar: R0 en producción con un solo tenant real, guardianas en verde, autenticación con límite de intentos y sesión endurecida (plan 001), respaldos diarios (plan 010) con una restauración probada en `staging` antes del lanzamiento. Falta, y se mide al iniciar: segunda barrera de aislamiento, 2FA, restauración a fondo, borrado y exportación por persona, retención. `[PENDIENTE: plazos de retención por categoría y quién los aprueba]`.

## 3. Decisiones

### DA-1 · Método de 2FA — se recomienda **TOTP con códigos de recuperación**

Aplicación autenticadora y códigos de un solo uso; el correo no es segundo factor. Se usa lo que ofrece el framework o el kit de inicio y Filament; no se escribe criptografía propia. Alternativa descartada: SMS, por costo y riesgo de intercambio de SIM.

### DA-2 · RLS — se recomienda **política por tabla con `app.organization_id` y un rol de aplicación sin `BYPASSRLS`**

`SET LOCAL app.organization_id` por transacción; el rol de migraciones es distinto del de la aplicación; las tablas globales declaradas quedan fuera. RLS complementa y no reemplaza el scope y las Policies (`docs/database-design.md` §3). Despliegue gradual: primero `staging`, luego producción, con prueba de acceso negado y permitido.

### DA-3 · Escaneo de archivos — se recomienda **decidir con dos opciones medidas**

(a) escaneo asíncrono con un servicio de antivirus antes de que un organizador descargue; (b) seguir sin escaneo con las compensaciones de RC-3. `[PENDIENTE: decisión de Antonio con base en el volumen de archivos y el costo]`. Se registra como ADR corto.

### DA-4 · Anonimizar o borrar — se recomienda **anonimizar** (sustituir datos personales por valores no identificables) para conservar agregados y evidencia

Se borra de forma definitiva solo lo que no obligue a conservar la ley (datos de facturación se conservan por plazo fiscal, ver `docs/privacidad.md`).

## 4. Contrato

- **Rutas web:** configuración y desafío de 2FA en los paneles (Filament); acciones «exportar datos» y «anonimizar» sobre un `Contact` en el panel de organizadores, por `POST` con CSRF y confirmación; sin rutas JSON públicas y sin entradas nuevas en `docs/api/endpoints.md`.
- **Form Requests:** `EnableTwoFactorRequest`, `ConfirmTwoFactorRequest`, `ExportPersonDataRequest`, `AnonymizePersonRequest` (con confirmación explícita y motivo).
- **Actions:** `RequireTwoFactor` (middleware y regla), `ExportPersonData` (archivo en disco privado con enlace temporal), `AnonymizePerson`, `ApplyRetentionPolicy` (programada).
- **Cambios de base de datos (propuesta §6 de `docs/database-design.md`):**
  1. *Necesidad:* defensa en profundidad del aislamiento y cumplimiento de derechos de las personas; dueño: plataforma y privacidad.
  2. *Modelo:* migración de esquema que habilita RLS y crea políticas por cada tabla con `organization_id`; rol de aplicación sin `BYPASSRLS`; columnas de 2FA en `users` (secreto cifrado, confirmado en, códigos de recuperación con hash); `retention_policies` (organización, categoría, plazo en días) y `anonymized_at` en las tablas con datos personales. Ámbito: plataforma (2FA) y organización (el resto).
  3. *Integridad:* un esquema por rol de base: `app` sujeto a RLS y `migrator` propietario; pruebas de acceso permitido y denegado; sin disminuir restricciones existentes.
  4. *Cambio:* RLS en dos pasos («expandir y contraer»): primero políticas permisivas en `staging` con registro de rechazos; luego estrictas. Reversa: deshabilitar las políticas por tabla (descrita y probada en `staging`) sin perder datos.
  5. *Alternativa descartada:* una base de datos por organización (ADR-0003).
- **Variables de entorno nuevas:** credenciales del rol de aplicación y del rol de migraciones; `[PENDIENTE: servicio de antivirus si se elige la opción (a) de DA-3]`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Todos los administradores y roles de plataforma deben activar 2FA. Exportar o anonimizar a una persona: administrador de organización y quien tenga `privacy.manage`. Soporte de plataforma: solo con acceso concedido y auditado |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `ContactPolicy` (`export`, `anonymize`), regla `TwoFactorRequired`; `tests/Feature/Privacy/` y `tests/Feature/Auth/` con permitido y denegado por rol |
| ¿Modelos nuevos con datos de organización? | `RetentionPolicy` con `BelongsToOrganization`; la 2FA vive en `users` (plataforma) |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No cruza eventos; exportar y anonimizar operan por organización |
| ¿Datos personales nuevos? | Secreto de 2FA (cifrado) y códigos de recuperación (con hash); la exportación contiene datos personales y se entrega con enlace temporal; actualizar el inventario de `docs/privacidad.md` |
| ¿Se suben archivos? | No; la exportación se genera en disco privado con nombre generado y caducidad |
| ¿Dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos? | Aviso de activación y de desactivación de 2FA, y aviso de enlace de exportación; registrados en el catálogo del plan 008, por cola |
| ¿Qué se registra en la auditoría? | Activación y baja de 2FA, intentos fallidos, exportaciones y anonimizaciones (quién, a quién, cuándo), uso de `withoutOrganizationScope()`, ejecuciones de retención y restauraciones |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Propuesta de datos y ADR de recortes de seguridad aprobados | S |
| F1 | 2FA obligatoria y códigos de recuperación (H-014-1) | M |
| F2 | RLS en `staging`, pruebas permitido y denegado, luego producción (H-014-2) | M |
| F3 | Ensayo de restauración y runbooks (H-014-3) | S |
| F4 | Exportar y anonimizar (H-014-4) | M |
| F5 | Retención programada (H-014-6) y decisión de escaneo (H-014-5) | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-014-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-014-1 · 2FA obligatoria

- **CA-014-1:** *Dado* un administrador de organización sin 2FA, *cuando* inicia sesión, *entonces* es redirigido a configurarla y no accede a ninguna página del panel hasta confirmarla.
- **CA-014-2:** *Dado* un usuario con 2FA activa, *cuando* ingresa un código TOTP inválido o repetido, *entonces* se rechaza, no se inicia sesión y se cuenta para el límite de intentos.
- **CA-014-3:** *Dado* un usuario que perdió su autenticador, *cuando* usa un código de recuperación válido, *entonces* accede y ese código queda inutilizable; un código ya usado se rechaza.
- **CA-014-4:** *Dado* un rol de plataforma o administrador, *cuando* intenta desactivar su 2FA, *entonces* se deniega; solo otro administrador puede reiniciarla, con auditoría.
- **CA-014-5:** *Dado* un evaluador externo invitado, *cuando* entra por primera vez, *entonces* se le exige 2FA según la política `[PENDIENTE: ¿obligatoria para evaluadores o solo para administradores?]`.
- **CA-014-6:** *Dado* un inicio de sesión desde un dispositivo nuevo, *cuando* se completa, *entonces* llega el aviso por correo y queda auditado.

### H-014-2 · RLS de PostgreSQL

- **CA-014-7:** *Dado* el rol de la aplicación y una tabla con `organization_id`, *cuando* se consulta sin `app.organization_id` establecido, *entonces* no se devuelven filas.
  Manual: ejecutar la consulta en `psql` con el usuario de la aplicación y comprobar cero filas (antes CA-001-12).
- **CA-014-8:** *Dado* `app.organization_id` igual a A, *cuando* se consulta una tabla con tenant, *entonces* solo se devuelven filas de A aunque el scope de la aplicación se omita.
- **CA-014-9:** *Dado* `app.organization_id` igual a A, *cuando* se inserta o actualiza una fila con `organization_id` de B, *entonces* la base lo rechaza.
- **CA-014-10:** *Dado* una transacción terminada, *cuando* la conexión se reutiliza, *entonces* `app.organization_id` ya no tiene el valor anterior (se usa `SET LOCAL`).
- **CA-014-11:** *Dado* el rol de migraciones, *cuando* ejecuta una migración, *entonces* no depende de RLS; el rol de la aplicación no tiene `BYPASSRLS`.
- **CA-014-12:** *Dado* todas las tablas con `organization_id`, *cuando* corre la guardiana, *entonces* cada una tiene RLS habilitado y una política; una tabla nueva sin política hace fallar la prueba.

### H-014-3 · Restauración y runbooks

- **CA-014-13:** *Dado* un respaldo de producción reciente, *cuando* se restaura en `staging`, *entonces* la aplicación arranca, las migraciones coinciden y el conteo de propuestas coincide con el origen.
  Manual: registrar fecha, qué se restauró, resultado y tiempo en la tabla de `docs/operacion.md` §5.
- **CA-014-14:** *Dado* los casos listados en `docs/operacion.md` §9, *cuando* se revisa el documento, *entonces* cada caso (sitio caído, cola atrasada, correo que no llega, restauración, sospecha de fuga entre organizaciones, secreto expuesto) tiene su guía con pasos.
  Manual: leer cada guía con alguien que no la escribió y ejecutarla en `staging`.
- **CA-014-15:** *Dado* un respaldo restaurado, *cuando* se revisan los datos, *entonces* no se usan datos reales sin anonimizar en un entorno que no sea producción.

### H-014-4 · Exportar y anonimizar

- **CA-014-16:** *Dado* un `Contact` con propuestas, evaluaciones y envíos de correo, *cuando* un administrador de su organización exporta sus datos, *entonces* se genera un archivo con solo sus datos, en disco privado, con enlace temporal, y queda auditado.
- **CA-014-17:** *Dado* un `Contact`, *cuando* se anonimiza, *entonces* nombre, correo, teléfono, biografía y comentarios personales se sustituyen por valores no identificables, se conservan los agregados y se registra `anonymized_at`.
- **CA-014-18:** *Dado* una persona con datos de facturación sujetos a plazo fiscal, *cuando* se anonimiza, *entonces* esos datos se conservan y el resultado lo informa.
- **CA-014-19:** *Dado* un administrador de la organización A, *cuando* intenta exportar o anonimizar a un `Contact` de B, *entonces* la respuesta es 404.
- **CA-014-20:** *Dado* una anonimización pedida sin confirmación explícita, *cuando* se envía, *entonces* se rechaza y no cambia ningún dato.
- **CA-014-21:** *Dado* un contacto anonimizado, *cuando* un autor intenta usar su enlace de acceso, *entonces* se rechaza con un mensaje genérico.

### H-014-5 · Escaneo de archivos

- **CA-014-22:** *Dado* las opciones de DA-3, *cuando* se toma la decisión, *entonces* queda registrada en un ADR corto con fecha, dueño y el punto de deuda correspondiente cerrado o reprogramado.
  Manual: revisar el ADR y `docs/sprints/deuda-tecnica.md`.
- **CA-014-23:** *Dado* que se elige escaneo asíncrono, *cuando* un archivo está pendiente o resulta infectado, *entonces* no se puede descargar y el organizador ve su estado.

### H-014-6 · Retención y anonimización programadas

- **CA-014-24:** *Dado* una política de retención de N días para una categoría, *cuando* corre el trabajo programado, *entonces* se anonimizan o eliminan los datos vencidos de esa categoría y de ninguna otra.
- **CA-014-25:** *Dado* un fallo a mitad de la ejecución, *cuando* el trabajo se reintenta, *entonces* es idempotente: no repite ni deja datos a medias.
- **CA-014-26:** *Dado* un administrador de A, *cuando* configura plazos, *entonces* solo afectan a A; cada ejecución se audita con totales, sin datos personales.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| RLS bloquea consultas legítimas | Despliegue gradual con registro en `staging`; pruebas de acceso permitido y denegado (CA-014-7 a 12); reversa descrita |
| Pérdida de acceso por 2FA | Códigos de recuperación y restablecimiento por otro administrador, auditado (CA-014-3, 4) |
| Anonimizar datos que deben conservarse | Excepción fiscal (CA-014-18) y confirmación explícita (CA-014-20) |
| Restauración que nunca se probó | Ensayo con evidencia en `docs/operacion.md` (CA-014-13) |
| Retraso por el receso de fin de año | Meta 18-dic-2026; orden de prioridad: RLS y 2FA primero |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
