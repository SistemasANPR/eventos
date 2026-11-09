# feat: núcleo multi-tenant (organizaciones, contexto, aislamiento y acceso)

> Primer plan del proyecto y mínimo de R0 (épica E-02 de `docs/plan-de-desarrollo.md`). Es la base de todo lo demás. RLS de PostgreSQL y 2FA quedan fuera de este plan (RC-1 y RC-2) y pasan al plan 014 (R1).

- **Fecha:** 2026-11-09
- **Estado:** En curso
- **Esfuerzo:** L
- **Deuda relacionada:** RLS de PostgreSQL (P1, RC-1) y 2FA (P1, RC-2), ambas con fecha límite 18-dic-2026 y dueño Antonio; se registrarán en `docs/sprints/deuda-tecnica.md` al aprobar el ADR-0011.

## 1. Objetivo y alcance

Dejar listo el aislamiento entre organizaciones **antes** de construir cualquier módulo, y el acceso seguro de los organizadores: modelo de organización y membresía, contexto actual resuelto desde la URL, scope global que falla cerrado, asignación automática al crear, roles y Policies por organización con su matriz rol × permiso, comandos para crear la primera organización y su primer administrador, inicio de sesión y recuperación de contraseña endurecidos, y las pruebas guardianas funcionando con modelos reales.

**Dentro:** modelos `Organization` y membresía de usuarios; `BelongsToOrganization`; `CurrentOrganization`; `MissingOrganizationContext`; middleware `SetOrganizationFromRoute` (esquema único de URL `/{organization:slug}/...`, D1); `TenantCacheKey` y `TenantStoragePath`; roles de `spatie/laravel-permission` con la organización como *team* (`org_admin`, `organizer`, `reviewer`) y usuario de plataforma fuera de los *teams* (`users.is_platform_admin`); matriz rol × permiso con su seeder y su prueba; Policies; bitácora de auditoría para el acceso sin scope, los cambios de rol y los inicios de sesión; comandos `app:create-organization` (con su primer administrador) y `app:create-platform-user` (sin panel de plataforma, RC-7); autenticación del framework con registro abierto desactivado, límite de intentos en inicio de sesión y recuperación, sesión endurecida y aviso de inicio de sesión desde un dispositivo nuevo (compensación de RC-2); `Contact` como modelo real con tenant (autores y ponentes) que además ejercita las guardianas.

**Fuera:** eventos y todo módulo de negocio (plan 003 en adelante); gestión de usuarios desde el panel y sus Actions (plan 003, H-003-3); **RLS de PostgreSQL** (RC-1, plan 014; el criterio que tenía este plan no se reutiliza); **2FA** (RC-2, plan 014); `event_assignments` (plan 011, R1); panel de plataforma y onboarding self-service (R5); resolución por subdominio o dominio propio por organización (R3 y R5).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-001-1 | Como organizador, quiero que mis datos estén aislados de otras organizaciones, para que nadie ajeno los vea | M | H-002-1 |
| H-001-2 | Como equipo técnico, quiero contexto explícito en jobs y comandos, acceso de plataforma auditado y comandos para crear la primera organización y su administrador, para no perder el aislamiento fuera de HTTP y poder arrancar | S | H-001-1, H-008-6 (hasta entonces `Mail::fake()`) |
| H-001-3 | Como administrador de organización, quiero roles y Policies por organización, para que cada persona haga solo lo suyo | M | H-001-1 |
| H-001-4 | Como organizador, quiero iniciar sesión y recuperar mi contraseña con protección contra abuso, para entrar con seguridad | M | H-001-3, H-008-6 (hasta entonces `Mail::fake()`) |

H-001-2 y H-001-4 solo se marcan como terminadas cuando existe el envío real por cola de H-008-6; antes se prueban con `Mail::fake()`.

## 2. Punto de partida

Medido el 2026-11-09: el repositorio contiene solo la plantilla (documentos, reglas, pruebas guardianas y CI). No existen `composer.json`, `artisan`, `Dockerfile` ni `docker-compose.yml`; el proyecto Laravel se crea en la historia H-002-1 (plan 002). Versión de Laravel, kit de inicio y estado de `composer check` y `npm run build`: `[PENDIENTE: se registran al cerrar H-002-1]`.

## 3. Decisiones

- **DA-1 · Alcance de los roles** — **organización como *team* de `spatie/laravel-permission`**; `event_assignments` llega en R1 (plan 011). Única fuente de roles: los de spatie. `organization_user` es solo membresía. Cerrada con la recomendación (ADR-0003 DA-1) una vez resuelto el spike S-1.
- **DA-2 · RLS de PostgreSQL** — **diferida** (RC-1): deuda P1 con fecha 18-dic-2026, obligatoria antes de la segunda organización; se implementa en el plan 014 (H-014-2).
- **DA-3 · Personas y membresías** — `User` con tabla `organization_user` (membresía, `status` y `deactivated_at`); los datos de contacto por organización viven en `Contact` (ADR-0003 DA-3).
- **DA-4 · Resolución del contexto** — esquema único de URL pública `/{organization:slug}/events/{event:slug}/...` (D1). El middleware `SetOrganizationFromRoute` resuelve la organización por su `slug` y establece `CurrentOrganization` **antes** del binding de modelos con tenant. `Organization` es modelo raíz sin scope. En el panel, tenant de Filament. Aunque en R0 solo exista una organización, la URL ya lleva su `slug`; subdominio y dominio propio quedan para R3 y R5 (ADR-0004 DA-4).
- **DA-5 · Comprobación del spike S-1** — si Filament multi-tenant y los *teams* de spatie no encajan, se documenta la discrepancia y se pregunta antes de implementar H-001-3.
- **DA-6 · Valores de acceso propuestos** — inicio de sesión: 5 intentos por 15 minutos por correo e IP; solicitud de restablecimiento: 5 solicitudes por 15 minutos por correo y por IP; caducidad del enlace de restablecimiento: 60 minutos; contraseña mínima de 12 caracteres y fuera de la lista de expuestas; sesión inactiva: 120 minutos. Antonio los aprueba junto con el plan.
- **DA-7 · Matriz rol × permiso** — la de §4; los permisos del `organizer` son una propuesta que Antonio aprueba con el plan.
- **DA-8 · Usuario de plataforma** — marca `users.is_platform_admin` fuera de los *teams* de spatie (un rol con *team* obligaría a pertenecer a una organización). No es asignable por asignación masiva ni desde ninguna interfaz; solo lo crea el comando `app:create-platform-user`.

## 4. Contrato

- **Clases:** `App\Models\Organization`, `App\Models\Concerns\BelongsToOrganization`, `App\Support\Tenancy\CurrentOrganization`, `OrganizationScope`, `App\Support\Tenancy\TenantCacheKey`, `App\Support\Tenancy\TenantStoragePath`, `App\Http\Middleware\SetOrganizationFromRoute`, `App\Http\Middleware\EnsureActiveMembership` y `App\Support\Tenancy\Exceptions\MissingOrganizationContext`.
- **Comandos:**
  - `php artisan app:create-organization {slug} {name} --admin-email= --admin-name=` (solo plataforma; establece el contexto con `CurrentOrganization::run()`; crea la organización y su primer `org_admin`, y encola `team.set_password`; idempotente por `slug`).
  - `php artisan app:create-platform-user {email} {name}` (crea el usuario con `is_platform_admin`; encola el correo de contraseña).
- **Form Requests:** inicio de sesión, solicitud y restablecimiento de contraseña (revisados con reglas de longitud mínima y lista de contraseñas expuestas).
- **Rutas web:** login del panel de Filament como único acceso de organizadores; las rutas de inicio de sesión del kit de inicio redirigen a él y el registro responde 404; grupo `/{organization:slug}/...` con el middleware. Sin rutas JSON públicas ni webhooks (nada que añadir a `docs/api/endpoints.md`).
- **Notificaciones nuevas del catálogo (plan 008):** `auth.password_reset` (crítica), `team.set_password` (crítica) y `auth.new_device_login` (crítica).
- **Matriz rol × permiso** (sembrada de forma idempotente por el seeder, que usa `CurrentOrganization::run()` para el *team*):

| Permiso | `org_admin` | `organizer` | `reviewer` |
|---|:-:|:-:|:-:|
| `events.manage` | sí | sí | no |
| `events.publish` | sí | no | no |
| `privacy_notices.manage` | sí | no | no |
| `team.manage` | sí | no | no |
| `calls.manage` | sí | sí | no |
| `tracks.manage` | sí | sí | no |
| `forms.manage` | sí | sí | no |
| `submissions.view` | sí | sí | no |
| `submissions.export` | sí | sí | no |
| `files.download` | sí | sí | no |
| `mail-templates.view` | sí | sí | no |
| `mail-templates.manage` | sí | no | no |
| `personal_data.manage` | sí | no | no |

  El `reviewer` (R1) no tiene permisos de organizador; sus permisos propios llegan con el plan 011. El usuario de plataforma no recibe permisos de organización por defecto.
- **Convención de claves compuestas:** toda tabla con tenant a la que otra tabla hace referencia declara `unique(id, organization_id)` para que las claves foráneas compuestas impidan referencias entre organizaciones (la aplican `contacts` aquí, `events` en el plan 002 y calls, tracks y forms en los planes 004 y 005).
- **Propuesta de datos (`docs/database-design.md` §6):**
  - *Necesidad:* aislar a cada organización, dar membresías y roles, y guardar las personas de contacto; dueño del módulo: núcleo.
  - *Modelo:*
    - `organizations` (UUID, nombre, `slug`, estado; ámbito plataforma).
    - `users` (se añade `is_platform_admin boolean NOT NULL DEFAULT false`, fuera de `$fillable`).
    - `organization_user` (UUID, usuario, organización, `status` `active|deactivated`, `deactivated_at`; ámbito organización; **solo membresía, sin rol**).
    - Tablas de roles y permisos del paquete con la organización como *team* (única fuente de roles).
    - `activity_log`.
    - `contacts` (`organization_id`, `full_name`, `email`, `country` nullable; columnas definitivas, el plan 006 solo añade lo que le falte).
    - `known_devices` (usuario, huella en hash, `last_seen_at`) para el aviso de dispositivo nuevo.
  - *Integridad:*
    - `organization_id uuid NOT NULL` con clave foránea.
    - `unique(slug)` en `organizations` (el `slug` va en la URL), con formato válido y lista de reservados.
    - `unique(organization_id, user_id)` en la membresía y `CHECK` de `status`.
    - `unique(organization_id, lower(email))` y `unique(id, organization_id)` en `contacts`.
  - *Cambio:* migraciones nuevas sin datos previos; las genera el agente y solo una persona las aplica contra una base real.
  - *Alternativa descartada:* guardar el rol también en `organization_user` (dos fuentes de verdad que se desfasan) y paquete de tenancy de terceros (ADR-0003; dependencia grande para algo que el contrato resuelve en poco código).
  - *Preguntas de negocio:* ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | La matriz de §4. Plataforma (`is_platform_admin`): crear organizaciones y usuarios de plataforma con los comandos y usar `withoutOrganizationScope()` con auditoría; no ve datos de una organización por defecto. `org_admin`: todos los permisos de la matriz en su organización. `organizer`: los marcados en la matriz. `reviewer`: ninguno de organizador |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `OrganizationPolicy` y la política de membresías (las Actions de gestión de equipo son del plan 003); una prueba por rol y permiso de la matriz, permitido y denegado, en `tests/Feature/Tenancy/` y `tests/Feature/Auth/` |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | Sí: `Contact` (base real de autores) y `KnownDevice` (por usuario, ámbito plataforma declarado). `Organization` es la raíz y se declara global en `TenantModels::GLOBAL_MODELS`. `organization_user` es relación con ámbito de organización |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No. Los roles son de organización; `event_assignments` llega en el plan 011 |
| ¿Se guardan datos personales nuevos? | Nombre y correo de usuarios y de `Contact`; huella de dispositivo en hash. Base legal: relación de trabajo con la organización y consentimiento; retención y borrado en `docs/privacidad.md` (se actualiza en el mismo cambio). Contraseñas solo con hash |
| ¿Se suben archivos? | No aplica (`TenantStoragePath` deja lista la ruta con organización para el plan 007) |
| ¿Hay dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos o notificaciones? | `auth.password_reset`, `team.set_password` y `auth.new_device_login` (las tres críticas, por cola, con predeterminada es/en en el catálogo del plan 008). Hasta que exista ese catálogo se prueba con `Mail::fake()` |
| ¿Qué se registra en la auditoría? | Cambios de rol, inicios de sesión y fallos relevantes, restablecimientos de contraseña, todo uso de `withoutOrganizationScope()`, la creación de organizaciones y de usuarios de plataforma |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Instalar paquetes autorizados, configurar `Model::shouldBeStrict()`, activar las guardianas en verde con cero modelos | S |
| F1 | `Organization`, membresías, `CurrentOrganization`, `BelongsToOrganization`, scope, excepción, `SetOrganizationFromRoute`, `TenantCacheKey`, `TenantStoragePath` y `Contact` (H-001-1) | M |
| F2 | `withoutOrganizationScope()` con autorización y auditoría; `CurrentOrganization::run()` para jobs; comandos `app:create-organization` y `app:create-platform-user` (H-001-2) | S |
| F3 | Roles por organización, matriz rol × permiso con su seeder, Policies, `EnsureActiveMembership` y panel de Filament con tenant (H-001-3) | M |
| F4 | Registro desactivado, inicio de sesión, recuperación de contraseña, límites, sesión endurecida y aviso de dispositivo nuevo (H-001-4) | M |

El agente genera las migraciones; solo una persona las aplica contra una base real. La fase de RLS se retiró de este plan (RC-1) y es la historia H-014-2 del plan 014.

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-001-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-001-1 · Aislamiento entre organizaciones

- **CA-001-1:** *Dado* registros de las organizaciones A y B, *cuando* el contexto es A, *entonces* cualquier consulta de un modelo con tenant devuelve solo los de A.
- **CA-001-2:** *Dado* que no hay contexto de organización, *cuando* se consulta un modelo con tenant, *entonces* se lanza `MissingOrganizationContext` (nunca una lista vacía ni todos los registros).
- **CA-001-3:** *Dado* el contexto A, *cuando* se crea un registro sin `organization_id`, *entonces* se le asigna A.
- **CA-001-4:** *Dado* el contexto A, *cuando* se intenta crear un registro con el `organization_id` de B, *entonces* se rechaza.
- **CA-001-5:** *Dado* una ruta de prueba `/{organization:slug}/contacts/{contact}` con binding acotado y un `Contact` de B, *cuando* se abre bajo el `slug` de A, *entonces* la respuesta es 404 (no 403).
- **CA-001-6:** *Dado* el `slug` de una organización, *cuando* se crea una con un `slug` ya usado, con formato inválido o de la lista de reservados (`admin`, `up`, `login`, `register`, `api`), *entonces* se rechaza; un `slug` nuevo y válido se acepta.
- **CA-001-7:** *Dado* cualquier modelo de `app/Models`, *cuando* corren las guardianas, *entonces* `ModelsAreScopedTest` y `TenantIsolationTest` pasan, y un modelo nuevo sin declarar su ámbito las hace fallar.
- **CA-001-8:** *Dado* un caché o una ruta de archivo de un modelo con tenant, *cuando* se construye su clave con `TenantCacheKey` o `TenantStoragePath`, *entonces* incluye la organización, y sin contexto lanza `MissingOrganizationContext`.
- **CA-001-9:** *Dado* una ruta bajo `/{organization:slug}/...`, *cuando* se abre, *entonces* `SetOrganizationFromRoute` establece el contexto de esa organización antes del binding de modelos con tenant; con un `slug` inexistente responde 404.
- **CA-001-10:** *Dado* una petición que establece el contexto de A, *cuando* termina (con éxito o con excepción), *entonces* `CurrentOrganization` queda vacío y la siguiente petición en el mismo proceso no hereda a A.

### H-001-2 · Contexto en jobs y comandos, acceso de plataforma y comandos de arranque

- **CA-001-11:** *Dado* un usuario sin `is_platform_admin`, *cuando* llama a `withoutOrganizationScope()`, *entonces* se deniega; con la marca, se permite y queda registro de auditoría.
- **CA-001-12:** *Dado* un job o comando, *cuando* ejecuta `CurrentOrganization::run($org, fn)`, *entonces* el contexto se establece durante la ejecución y se restaura después, incluso si lanza una excepción.
- **CA-001-13:** *Dado* un job encolado sin contexto, *cuando* intenta consultar un modelo con tenant, *entonces* falla con `MissingOrganizationContext` y el fallo es visible en la cola.
- **CA-001-14:** *Dado* el comando `app:create-organization` con un `slug` nuevo y `--admin-email`, *cuando* lo ejecuta la plataforma, *entonces* crea la organización, su primer `org_admin` con membresía activa, encola `team.set_password` y deja auditoría; *cuando* el `slug` ya existe, *entonces* no duplica la organización ni el administrador, informa el error y sale con código distinto de 0.
- **CA-001-15:** *Dado* el comando `app:create-organization` con un `slug` o un correo inválidos, *cuando* se ejecuta, *entonces* se rechaza y no se crea nada (ni la organización ni el usuario).
- **CA-001-16:** *Dado* el comando `app:create-platform-user`, *cuando* se ejecuta, *entonces* crea un usuario con `is_platform_admin` y encola su correo de contraseña; *cuando* se intenta fijar `is_platform_admin` por asignación masiva o desde cualquier petición HTTP, *entonces* no se aplica.
- **CA-001-17:** *Dado* un usuario de plataforma sin membresía en A, *cuando* abre el panel de A o consulta un modelo con tenant sin `withoutOrganizationScope()`, *entonces* no obtiene acceso.

### H-001-3 · Roles y Policies por organización

- **CA-001-18:** *Dado* un usuario con membresía solo en A, *cuando* intenta cambiar el tenant a B en el panel, *entonces* se deniega.
- **CA-001-19:** *Dado* el seeder de roles, *cuando* se ejecuta dos veces para la organización A, *entonces* quedan los roles `org_admin`, `organizer` y `reviewer` con exactamente los permisos de la matriz de §4, sin duplicados, y la prueba compara la matriz declarada con la sembrada.
- **CA-001-20:** *Dado* cada permiso de la matriz y cada rol, *cuando* un usuario con ese rol intenta la acción protegida, *entonces* se permite solo si la matriz lo marca y se deniega con 403 en caso contrario (una prueba por rol y permiso, permitido y denegado).
- **CA-001-21:** *Dado* una acción sin Policy, *cuando* se invoca, *entonces* se deniega por defecto.
- **CA-001-22:** *Dado* un usuario sin ninguna membresía, *cuando* entra al panel, *entonces* no obtiene acceso a ninguna organización.
- **CA-001-23:** *Dado* el cambio de rol de un usuario de A, *cuando* se guarda, *entonces* se actualizan solo los roles de spatie de ese *team*, `organization_user` no guarda rol, el rol de un usuario en B no cambia y queda registro de auditoría con quién, a quién y de qué rol a cuál.
- **CA-001-24:** *Dado* una membresía con `status` `deactivated` o con `deactivated_at`, *cuando* el usuario hace una petición con una sesión abierta o inicia sesión, *entonces* `EnsureActiveMembership` corta la sesión en esa organización y responde 403 o redirige al inicio de sesión.

### H-001-4 · Inicio de sesión y recuperación de contraseña

- **CA-001-25:** *Dado* credenciales válidas, *cuando* el usuario inicia sesión, *entonces* se regenera el identificador de sesión y las cookies son `Secure`, `HttpOnly` y `SameSite=Lax`.
- **CA-001-26:** *Dado* credenciales inválidas, *cuando* se intenta iniciar sesión, *entonces* el mensaje no revela si el correo existe y la respuesta es homogénea.
- **CA-001-27:** *Dado* más de 5 intentos de inicio de sesión en 15 minutos desde el mismo correo e IP, *cuando* se hace otro intento, *entonces* responde 429 y registra el fallo en la auditoría.
- **CA-001-28:** *Dado* un correo registrado o no, *cuando* se solicita restablecer la contraseña, *entonces* la respuesta es idéntica y, si existe, se encola `auth.password_reset` con un enlace firmado, de un solo uso y de 60 minutos de caducidad.
- **CA-001-29:** *Dado* más de 5 solicitudes de restablecimiento en 15 minutos para el mismo correo o desde la misma IP, *cuando* se hace otra, *entonces* responde 429 y no se encola ningún correo adicional.
- **CA-001-30:** *Dado* un enlace de restablecimiento usado o caducado, *cuando* se abre, *entonces* se rechaza con un mensaje genérico y no cambia la contraseña.
- **CA-001-31:** *Dado* una contraseña nueva de menos de 12 caracteres o incluida en la lista de contraseñas expuestas, *cuando* se envía, *entonces* se rechaza con el error de validación correspondiente.
- **CA-001-32:** *Dado* una contraseña guardada, *cuando* se inspecciona la base o cualquier respuesta, registro o exportación, *entonces* solo existe el hash y nunca aparece en claro.
- **CA-001-33:** *Dado* una sesión inactiva más de 120 minutos, *cuando* el usuario vuelve, *entonces* debe iniciar sesión de nuevo.
- **CA-001-34:** *Dado* el kit de inicio de sesión, *cuando* se abre `/register` (GET o POST), *entonces* responde 404 y no se crea ningún usuario; *cuando* se abre el inicio de sesión del kit, *entonces* redirige al inicio de sesión del panel de Filament, que es el único acceso de organizadores.
- **CA-001-35:** *Dado* un usuario que inicia sesión desde un dispositivo no registrado en `known_devices`, *cuando* la sesión se establece, *entonces* se encola `auth.new_device_login` y el dispositivo queda registrado; *cuando* inicia desde un dispositivo ya conocido, *entonces* no se envía el aviso.
- **CA-001-36:** *Dado* el aviso `auth.new_device_login`, *cuando* se inspecciona su contenido y su registro, *entonces* no incluye contraseñas ni tokens y solo muestra fecha y un dispositivo aproximado.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Olvidar el contexto en jobs, comandos y seeders | La excepción hace evidente el olvido; `CurrentOrganization::run()` y pruebas por job |
| El scope global sorprende en consultas de plataforma | `withoutOrganizationScope()` con auditoría y lista cerrada de usos en el ADR-0003 |
| Una fábrica no acepta `organization_id` explícito | La guardiana falla con un mensaje claro; es parte de la definición de terminado |
| Sin RLS (RC-1), un fallo del scope no tiene segunda barrera | Una sola organización real, guardianas en verde, revisión humana de tenancy y RLS en el plan 014 antes de la segunda organización |
| Sin 2FA (RC-2), una contraseña robada da acceso a datos personales | Pocas cuentas, contraseñas fuertes con lista de expuestas, límite de intentos, sesión endurecida, aviso de dispositivo nuevo (CA-001-35) y 2FA en el plan 014 antes de dar acceso a evaluadores externos |
| Filament multi-tenant y los *teams* de spatie no encajan | Spike S-1 el 10-nov antes de H-001-3 (DA-5) |
| Registro abierto o dos pantallas de acceso heredados del kit de inicio | CA-001-34 desactiva el registro y deja un solo acceso |
| Nadie puede entrar tras crear la organización | `app:create-organization` crea el primer administrador con su correo de contraseña (CA-001-14) |
| Contexto de organización inexistente en rutas públicas anónimas | `SetOrganizationFromRoute` (CA-001-9) y la regla de URL única (D1) |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
