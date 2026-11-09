# feat: núcleo multi-tenant (organizaciones, contexto, aislamiento y acceso)

> Primer plan del proyecto y mínimo de R0 (épica E-02 de `docs/plan-de-desarrollo.md`). Es la base de todo lo demás. RLS de PostgreSQL y 2FA quedan fuera de este plan (RC-1 y RC-2) y pasan al plan 014 (R1).

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** RLS de PostgreSQL (P1, RC-1) y 2FA (P1, RC-2), ambas con fecha límite 18-dic-2026 y dueño Antonio; se registrarán en `docs/sprints/deuda-tecnica.md` al aprobar el ADR-0011.

## 1. Objetivo y alcance

Dejar listo el aislamiento entre organizaciones **antes** de construir cualquier módulo, y el acceso seguro de los organizadores: modelo de organización y membresía, contexto actual, scope global que falla cerrado, asignación automática al crear, roles y Policies por organización, inicio de sesión y recuperación de contraseña endurecidos, y las pruebas guardianas funcionando con modelos reales.

**Dentro:** modelos `Organization` y membresía de usuarios; `BelongsToOrganization`; `CurrentOrganization`; `MissingOrganizationContext`; resolución del contexto (un solo tenant real resuelto por configuración en R0, tenant de Filament en el panel); roles por organización (administrador de organización, miembro, plataforma/soporte); Policies; bitácora de auditoría para el acceso sin scope y para cambios de rol; comando para crear una organización (sin panel de plataforma, RC-7); autenticación del framework con límite de intentos, sesión endurecida y recuperación de contraseña; `Contact` como modelo de ejemplo con tenant para ejercitar las guardianas.

**Fuera:** eventos (plan 003) y todo módulo de negocio; **RLS de PostgreSQL** (RC-1, plan 014, CA-001-12 se retira de este plan y no se reutiliza su id); **2FA** (RC-2, plan 014); `event_assignments` (plan 011, R1); panel de plataforma y onboarding self-service (R5); resolución por subdominio o dominio propio por organización.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-001-1 | Como organizador, quiero que mis datos estén aislados de otras organizaciones, para que nadie ajeno los vea | M | H-002-1 |
| H-001-2 | Como equipo técnico, quiero contexto explícito en jobs y comandos y acceso de plataforma auditado, para no perder el aislamiento fuera de HTTP | S | H-001-1 |
| H-001-3 | Como administrador de organización, quiero roles y Policies por organización, para que cada persona haga solo lo suyo | M | H-001-1 |
| H-001-4 | Como organizador, quiero iniciar sesión y recuperar mi contraseña con protección contra abuso, para entrar con seguridad | M | H-001-3, H-008-6 |

## 2. Punto de partida

Medido el 2026-11-09: el repositorio contiene solo la plantilla (documentos, reglas, pruebas guardianas y CI). No existen `composer.json`, `artisan`, `Dockerfile` ni `docker-compose.yml`; el proyecto Laravel se crea en la historia H-002-1 (plan 002). Versión de Laravel, kit de inicio y estado de `composer check` y `npm run build`: `[PENDIENTE: se registran al cerrar H-002-1]`.

## 3. Decisiones

- **DA-1 · Alcance de los roles** — **organización como *team* de `spatie/laravel-permission***; `event_assignments` llega en R1 (plan 011). Cerrada con la recomendación (ADR-0003 DA-1) una vez resuelto el spike S-1.
- **DA-2 · RLS de PostgreSQL** — **diferida** (RC-1): deuda P1 con fecha 18-dic-2026, obligatoria antes de la segunda organización; se implementa en el plan 014 (H-014-2).
- **DA-3 · Personas y membresías** — `User` con tabla `organization_user` (rol por organización); los datos de contacto por organización viven en `Contact` (ADR-0003 DA-3).
- **DA-4 · Resolución del contexto** — en R0 hay un solo tenant real y el contexto se resuelve por **configuración**; en el panel, tenant de Filament. Subdominio y dominio propio quedan para R3 y R5 (ADR-0004 DA-4).
- **DA-5 · Comprobación del spike S-1** — si Filament multi-tenant y los *teams* de spatie no encajan, se documenta la discrepancia y se pregunta antes de implementar H-001-3.

## 4. Contrato

- **Clases:** `App\Models\Organization`, `App\Models\Concerns\BelongsToOrganization`, `App\Support\Tenancy\CurrentOrganization`, `OrganizationScope` y `App\Support\Tenancy\Exceptions\MissingOrganizationContext`.
- **Comando:** `php artisan app:create-organization` (solo plataforma; establece el contexto con `CurrentOrganization::run()` y es idempotente por `slug`).
- **Form Requests:** inicio de sesión, solicitud y restablecimiento de contraseña (los del kit de inicio, revisados con reglas de longitud mínima y lista de contraseñas expuestas).
- **Rutas web** del kit de inicio y del panel de Filament; sin rutas JSON públicas ni webhooks (nada que añadir a `docs/api/endpoints.md`).
- **Propuesta de datos (`docs/database-design.md` §6):**
  - *Necesidad:* aislar a cada organización y dar membresías y roles; dueño del módulo: núcleo.
  - *Modelo:* `organizations` (UUID, nombre, `slug`, estado; ámbito plataforma), `organization_user` (usuario, organización, rol; ámbito organización), tablas de roles y permisos del paquete con la organización como *team*, `activity_log`, y `contacts` (ejemplo con tenant: nombre, correo, `organization_id`).
  - *Integridad:* `organization_id uuid NOT NULL` con clave foránea; `unique(organization_id, slug)` en lo que lo requiera; `unique(organization_id, user_id)` en la membresía; `contacts` único por `(organization_id, email)`.
  - *Cambio:* migraciones nuevas sin datos previos; las genera el agente y solo una persona las aplica contra una base real.
  - *Alternativa descartada:* paquete de tenancy de terceros (ADR-0003); se descartó por dependencia grande para algo que el contrato resuelve en poco código.
  - *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Plataforma (soporte): crear organizaciones y usar `withoutOrganizationScope()` con auditoría. Administrador de organización: gestionar usuarios y roles de su organización. Miembro: usar el panel con los permisos que su rol le dé; no gestiona usuarios |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `OrganizationPolicy` y política de membresías; una prueba por rol y acción, permitido y denegado, en `tests/Feature/Tenancy/` y `tests/Feature/Auth/` |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | Sí: `Contact` (ejemplo y base real de autores). `Organization` es la raíz y se declara global en `TenantModels::GLOBAL_MODELS`. `organization_user` es relación con ámbito de organización |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. Los roles son de organización; `event_assignments` llega en el plan 011 |
| ¿Se guardan datos personales nuevos? | Nombre y correo de usuarios y de `Contact`. Base legal: relación de trabajo con la organización y consentimiento; retención y borrado en `docs/privacidad.md` (se actualiza en el mismo cambio). Contraseñas solo con hash |
| ¿Se suben archivos? | No aplica |
| ¿Hay dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos o notificaciones? | `auth.password_reset` (crítica), por cola con su predeterminada es/en en el catálogo del plan 008. Hasta que exista ese catálogo se prueba con `Mail::fake()` |
| ¿Qué se registra en la auditoría? | Cambios de rol, inicios de sesión y fallos relevantes, restablecimientos de contraseña, todo uso de `withoutOrganizationScope()`, y la creación de organizaciones |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Instalar paquetes autorizados, configurar `Model::shouldBeStrict()`, activar las guardianas en verde con cero modelos | S |
| F1 | `Organization`, membresías, `CurrentOrganization`, `BelongsToOrganization`, scope, excepción y `Contact` de ejemplo (H-001-1) | M |
| F2 | `withoutOrganizationScope()` con autorización y auditoría; `CurrentOrganization::run()` para jobs; comando de organización (H-001-2) | S |
| F3 | Roles por organización, Policies y panel de Filament con tenant (H-001-3) | M |
| F4 | Inicio de sesión, recuperación de contraseña, límite de intentos y sesión endurecida (H-001-4) | M |

El agente genera las migraciones; solo una persona las aplica contra una base real. La fase de RLS se retiró de este plan (RC-1) y es la historia H-014-2 del plan 014.

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-001-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo. El número 12 queda vacío a propósito: era el criterio de RLS, que pasa al plan 014 con otro identificador y no se reutiliza.

### H-001-1 · Aislamiento entre organizaciones

- **CA-001-1:** *Dado* registros de las organizaciones A y B, *cuando* el contexto es A, *entonces* cualquier consulta de un modelo con tenant devuelve solo los de A.
- **CA-001-2:** *Dado* que no hay contexto de organización, *cuando* se consulta un modelo con tenant, *entonces* se lanza `MissingOrganizationContext` (nunca una lista vacía ni todos los registros).
- **CA-001-3:** *Dado* el contexto A, *cuando* se crea un registro sin `organization_id`, *entonces* se le asigna A.
- **CA-001-4:** *Dado* el contexto A, *cuando* se intenta crear un registro con el `organization_id` de B, *entonces* se rechaza.
- **CA-001-5:** *Dado* un identificador de un registro de B, *cuando* se accede desde una ruta con binding acotado en el contexto A, *entonces* la respuesta es 404 (no 403).
- **CA-001-6:** *Dado* un mismo `slug`, *cuando* se crea en dos organizaciones distintas, *entonces* se permite; *cuando* se repite en la misma, *entonces* se rechaza.
- **CA-001-7:** *Dado* cualquier modelo de `app/Models`, *cuando* corren las guardianas, *entonces* `ModelsAreScopedTest` y `TenantIsolationTest` pasan, y un modelo nuevo sin declarar su ámbito las hace fallar.
- **CA-001-8:** *Dado* un caché o una ruta de archivo de un modelo con tenant, *cuando* se construye su clave, *entonces* incluye la organización.

### H-001-2 · Contexto en jobs y comandos, y acceso de plataforma

- **CA-001-9:** *Dado* un usuario sin el rol de plataforma, *cuando* llama a `withoutOrganizationScope()`, *entonces* se deniega; con el rol, se permite y queda registro de auditoría.
- **CA-001-10:** *Dado* un job o comando, *cuando* ejecuta `CurrentOrganization::run($org, fn)`, *entonces* el contexto se establece durante la ejecución y se restaura después, incluso si lanza una excepción.
- **CA-001-11:** *Dado* un job encolado sin contexto, *cuando* intenta consultar un modelo con tenant, *entonces* falla con `MissingOrganizationContext` y el fallo es visible en la cola.
- **CA-001-13:** *Dado* el comando `app:create-organization` con un `slug` nuevo, *cuando* lo ejecuta la plataforma, *entonces* crea la organización y deja auditoría; *cuando* el `slug` ya existe, *entonces* no duplica y informa el error.

### H-001-3 · Roles y Policies por organización

- **CA-001-14:** *Dado* un usuario con membresía solo en A, *cuando* intenta cambiar el tenant a B en el panel, *entonces* se deniega.
- **CA-001-15:** *Dado* un administrador de A, *cuando* gestiona usuarios, *entonces* solo ve y modifica los de A (una prueba por rol y acción, permitido y denegado).
- **CA-001-16:** *Dado* un miembro sin permiso de administración, *cuando* intenta crear, cambiar el rol o eliminar a un usuario, *entonces* recibe 403 y no cambia nada.
- **CA-001-17:** *Dado* un cambio de rol, *cuando* se guarda, *entonces* queda registro de auditoría con quién, a quién y de qué rol a cuál.
- **CA-001-18:** *Dado* una acción sin Policy, *cuando* se invoca, *entonces* se deniega por defecto.
- **CA-001-19:** *Dado* un usuario sin ninguna membresía, *cuando* entra al panel, *entonces* no obtiene acceso a ninguna organización.

### H-001-4 · Inicio de sesión y recuperación de contraseña

- **CA-001-20:** *Dado* credenciales válidas, *cuando* el usuario inicia sesión, *entonces* se regenera el identificador de sesión y las cookies son `Secure`, `HttpOnly` y `SameSite=Lax`.
- **CA-001-21:** *Dado* credenciales inválidas, *cuando* se intenta iniciar sesión, *entonces* el mensaje no revela si el correo existe y la respuesta es homogénea.
- **CA-001-22:** *Dado* más del límite de intentos de inicio de sesión desde el mismo correo e IP, *cuando* se hace otro intento, *entonces* responde 429 y registra el fallo en la auditoría. `[PENDIENTE: número de intentos y ventana, propuesta a validar en el spike]`
- **CA-001-23:** *Dado* un correo registrado o no, *cuando* se solicita restablecer la contraseña, *entonces* la respuesta es idéntica y, si existe, se encola `auth.password_reset` con un enlace firmado, de un solo uso y de caducidad corta.
- **CA-001-24:** *Dado* un enlace de restablecimiento usado o caducado, *cuando* se abre, *entonces* se rechaza con un mensaje genérico y no cambia la contraseña.
- **CA-001-25:** *Dado* una contraseña nueva corta o incluida en la lista de contraseñas expuestas, *cuando* se envía, *entonces* se rechaza con el error de validación correspondiente.
- **CA-001-26:** *Dado* una contraseña guardada, *cuando* se inspecciona la base o cualquier respuesta, registro o exportación, *entonces* solo existe el hash y nunca aparece en claro.
- **CA-001-27:** *Dado* una sesión inactiva más del tiempo definido, *cuando* el usuario vuelve, *entonces* debe iniciar sesión de nuevo. `[PENDIENTE: caducidad de sesión]`
  Manual: iniciar sesión, esperar la caducidad configurada y recargar; confirmar la redirección al inicio de sesión (ancho ~1280 px).

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Olvidar el contexto en jobs, comandos y seeders | La excepción hace evidente el olvido; `CurrentOrganization::run()` y pruebas por job |
| El scope global sorprende en consultas de plataforma | `withoutOrganizationScope()` con auditoría y lista cerrada de usos en el ADR-0003 |
| Una fábrica no acepta `organization_id` explícito | La guardiana falla con un mensaje claro; es parte de la definición de terminado |
| Sin RLS (RC-1), un fallo del scope no tiene segunda barrera | Un solo tenant real, guardianas en verde, revisión humana de tenancy y RLS en el plan 014 antes de la segunda organización |
| Sin 2FA (RC-2), una contraseña robada da acceso a datos personales | Pocas cuentas, contraseñas fuertes con lista de expuestas, límite de intentos, sesión endurecida y 2FA en el plan 014 antes de dar acceso a evaluadores externos |
| Filament multi-tenant y los *teams* de spatie no encajan | Spike S-1 el 10-nov antes de H-001-3 (DA-5) |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
