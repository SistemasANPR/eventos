# feat: panel de plataforma para el super admin

> Plan de la épica de operación de plataforma (adelanta RC-7 de `docs/plan-de-desarrollo.md` §3.4). Es la especificación de SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código.

- **Fecha:** 2026-11-10
- **Estado:** En curso
- **Esfuerzo:** L
- **Deuda relacionada:** cierra DT-01 (`organization_id` propio en `activity_log`, fase F1); se apoya en DT-02 y en el plan 014 (2FA y RLS); adelanta RC-7 (hoy fijado a R5)

## 1. Objetivo y alcance

El equipo de la plataforma (usuarios con `is_platform_admin`, «soporte») hoy no tiene pantalla alguna: las organizaciones se crean con un comando y el usuario de plataforma **no puede entrar a ningún panel**. Este plan le da un panel propio, separado del de las organizaciones, para ver el estado de la plataforma (organizaciones, usuarios, eventos y auditoría) y operar lo mínimo (crear, suspender y reactivar organizaciones), con todo acceso entre organizaciones pasando por el salto de filtro auditado y con la 2FA obligatoria del plan 014.

La regla que gobierna el plan: **el panel de plataforma muestra metadatos de la plataforma, no el contenido de las organizaciones.** Las propuestas, los contactos y los archivos de una organización no se ven desde aquí (DA-4).

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-016-1 | Como usuario de plataforma, quiero entrar a un panel propio con mi cuenta, para operar la plataforma sin pertenecer a ninguna organización | M | H-001-4 |
| H-016-2 | Como usuario de plataforma, quiero ver, crear, suspender y reactivar organizaciones, para dar de alta clientes y cortar el acceso de uno que lo requiera | M | H-016-1 |
| H-016-3 | Como usuario de plataforma, quiero ver los usuarios de todas las organizaciones y a cuáles pertenecen, para dar soporte | S | H-016-1 |
| H-016-4 | Como usuario de plataforma, quiero ver los eventos de todas las organizaciones, para conocer qué se está operando | S | H-016-1 |
| H-016-5 | Como usuario de plataforma, quiero consultar la bitácora de auditoría de toda la plataforma, para investigar incidentes y verificar el uso del acceso entre organizaciones | M | H-016-1 |

**Dentro:** panel Filament `platform` (`/platform`) con su inicio de sesión y recuperación de contraseña; recursos de solo lectura de usuarios, eventos y auditoría; recurso de organizaciones con alta, suspensión y reactivación; efecto de la suspensión en el panel de la organización; `organization_id` propio en la bitácora (DT-01); Policy y Gate de plataforma; reserva del `slug` `platform`; habilitación controlada del panel (DA-1); pruebas y guardianas.

**Fuera:** acceso de soporte al **contenido** de una organización, con concesión y vigencia (DA-4; plan propio cuando exista contenido de autores, planes 006 en adelante); suplantar a un usuario; alta, promoción o baja de usuarios de plataforma (sigue siendo solo el comando, plan 001 DA-8); reinicio de 2FA por plataforma (lo añade el plan 014, DA-7); exportaciones; editar, publicar o borrar eventos y usuarios de otra organización; borrar organizaciones; correos de aviso por suspensión (catálogo del plan 008); métricas, tableros, planes y facturación del SaaS (R5); contexto de RLS para plataforma (lo define el plan 014, DA-8); portugués en este panel.

## 2. Punto de partida

Medido el 2026-11-10 en la rama `staging`:

- **Planes:** existen los planes 001 a 015; ninguno cubre un panel de plataforma. RC-7 («sin panel de plataforma: las organizaciones se crean con un comando») lo fija a R5.
- **Usuario de plataforma:** columna `users.is_platform_admin` (fuera de `$fillable`; plan 001, DA-8). Solo lo crea `app:create-platform-user` (`CreatePlatformUser`). En la base local hay 1 usuario, 0 de plataforma, 1 organización y 5 registros de auditoría.
- **Acceso al panel:** existe un solo panel, `admin` (`AdminPanelProvider`: path `admin`, tenant `Organization`, tema del plan 015). `User::canAccessPanel(Panel $panel)` **ignora el panel** y devuelve si el usuario tiene una membresía activa; por eso un usuario de plataforma sin membresía no entra a ningún panel (CA-001-17 lo exige para las organizaciones). `canResetPassword` sí permite al usuario de plataforma restablecer su contraseña.
- **Salto de filtro:** `OrganizationScopeBypass::apply()` es el único lugar que ejecuta `withoutOrganizationScope()`. Exige un usuario autenticado con `is_platform_admin` y registra `organization_scope_bypassed` con el modelo (un registro por consulta). Lo vigila `TenantBypassTest`.
- **Organización:** `Organization` es global (`GLOBAL_MODELS`), con `status` (`active`, `suspended`). Una organización suspendida responde 404 en el sitio público (CA-001-9), pero **ni el panel ni `getTenants()` la consideran**: sus miembros siguen entrando. `RESERVED_SLUGS` no incluye `platform`. No existe `OrganizationPolicy`.
- **Bitácora:** `activity_log` es la tabla estándar de Spatie **sin `organization_id`** (DT-01): la organización solo viaja dentro de `properties`. Los registros `log_name = auth` contienen IP y hash del correo (`docs/privacidad.md` §2).
- **2FA y RLS:** no existen; son el plan 014 (H-014-1 y DA-2). CA-014-1 ya incluye al rol `platform` entre los que deben activar 2FA.
- **Pruebas relacionadas:** `CreatePlatformUserCommandTest`, `WithoutOrganizationScopeTest`, `TenantBypassTest`, `AdminPanelTenancyTest`, `PasswordResetTest`, `AccessThrottleTest`.

## 3. Decisiones

Resumen: un panel separado en `/platform` (DA-2); solo metadatos, sin contenido de las organizaciones (DA-4); suspender bloquea el sitio y el panel (DA-3); bitácora con columna de organización y datos de acceso redactados (DA-5); usuarios de plataforma solo por comando (DA-6). Cerradas el 2026-11-10 con la recomendación de cada una: DA-1, DA-4, DA-5 y DA-9 (aprobadas por Antonio).

### DA-1 · Habilitación y 2FA — se recomienda **construirlo ahora, registrarlo solo en local o con `PLATFORM_PANEL_ENABLED=true`, y no habilitarlo en producción hasta que exista la 2FA del plan 014**

Opciones: (A) esperar a que el plan 014 (H-014-1) exista y construir todo después; (B) hacer una 2FA mínima dentro de este plan, duplicando el 014; (C) lanzar sin 2FA y registrar un recorte nuevo. Se recomienda **una variante de A que no bloquea el desarrollo**: el panel se construye ya, pero `config('platform.enabled')` (variable `PLATFORM_PANEL_ENABLED`, por defecto `false` salvo en `local` y `testing`) decide si se registra. En producción y `staging` queda apagado hasta que H-014-1 esté en el repositorio y se conecte su guardia; ese paso es el criterio CA-016-8. Evita tanto una 2FA duplicada como un panel con acceso entre organizaciones protegido solo por contraseña. Cerrada el 2026-11-10: se acepta que el panel exista en `local` y `testing` sin 2FA mientras tanto. El apagado en otros entornos lo hace un middleware del panel (responde 404), que se aplica también a las peticiones de Livewire.

### DA-2 · Panel separado o sección del panel de organizaciones — se recomienda **panel separado `platform`, sin *tenant***

Un panel propio (`/platform`, directorio `app/Filament/Platform/`, ADR-0004: un panel por rol) no mezcla el contexto de organización con el de plataforma: sin *tenant* ni `CurrentOrganization`, cualquier consulta accidental a un modelo con organización **falla cerrada** (`MissingOrganizationContext`). Una sección dentro de `admin` obligaría a ocultar y autorizar por pantalla dentro de un panel pensado para un *tenant*. La marca visible del panel («Plataforma») evita operar en el contexto equivocado.

### DA-3 · Qué hace suspender una organización — se recomienda **bloquear el sitio público y el panel; no tocar colas, datos ni correos**

Hoy suspender solo da 404 en el sitio público. Se añade: la organización suspendida no se ofrece como *tenant* y su URL directa responde 404, y una sesión abierta pierde el acceso en la petición siguiente. Los datos no se borran ni se anonimizan; los trabajos en cola y programados siguen (se revisará con el plan 010 si debe pausarse). Reactivar lo revierte. Cerrada el 2026-11-10: opción recomendada. El motivo es obligatorio y queda auditado. No hay aviso por correo (fuera).

### DA-4 · Qué significa «acceso entre organizaciones» — se recomienda **solo metadatos ahora; el acceso al contenido, con concesión de la organización, en un plan propio**

- **Nivel 1 (este plan):** vistas de plataforma de **metadatos** —organizaciones, usuarios del equipo, eventos, bitácora— con `withoutOrganizationScope()` auditado. Es el caso «agregados de la plataforma» del ADR-0003 §5.
- **Nivel 2 (fuera):** entrar al **contenido** de una organización (propuestas, contactos, archivos) como soporte. El plan 014 y el ADR-0003 hablan de «soporte con acceso concedido y auditado». Se recomienda que lo conceda un `org_admin` de esa organización, con vigencia corta (por ejemplo 24 h), solo lectura, motivo y aviso a los administradores. Hoy no hay contenido de autores que proteger (el plan 006 no existe), así que no se construye; se especificará cuando exista, y exigirá su propia revisión humana de tenancy. Cerrada el 2026-11-10: solo Nivel 1.

### DA-5 · Bitácora: `organization_id` y qué se muestra — se recomienda **cerrar DT-01 aquí (columna propia) y ocultar IP y hash de correo en los registros `auth`**

Filtrar por organización sin columna exige consultar `properties->>'organization_id'` (sin índice ni integridad). Se añade `activity_log.organization_id` (nullable: los hechos de plataforma no tienen organización), con relleno de lo existente (propuesta en §4, `docs/database-design.md` §6). El visor muestra fecha (UTC), evento, descripción, actor, organización y objeto; de los registros `auth` solo muestra evento, fecha y actor, nunca IP ni hash de correo; de los demás muestra los valores anterior y nuevo. Es solo lectura. Cerrada el 2026-11-10: opción recomendada.

### DA-6 · Usuarios de plataforma — se recomienda **mantener «solo por comando» y que el panel de usuarios sea de solo lectura**

El plan 001 (DA-8, CA-001-16) fija que `is_platform_admin` no se asigna desde ninguna interfaz. El panel no lo cambia. Quien crea el primer usuario de plataforma sigue usando `app:create-platform-user`.

### DA-7 · Reinicio de 2FA por plataforma — se recomienda **dejarlo al plan 014**

CA-014-5 pide que plataforma reinicie la 2FA de un administrador sin pares, con verificación fuera de banda. Esa acción vive en la pantalla de usuarios de este panel, pero la escribe el plan 014 junto con la 2FA.

### DA-8 · Interacción con RLS — se recomienda **definirla en el plan 014**

Con RLS activa (rol sin `BYPASSRLS`), `withoutOrganizationScope()` por sí solo no atraviesa la segunda barrera. El plan 014 debe definir el contexto de plataforma para lecturas entre organizaciones (por ejemplo una variable de sesión fijada solo por `OrganizationScopeBypass`, con políticas de solo lectura). Este plan solo **lee** entre organizaciones y **escribe** únicamente en `organizations` (tabla global) y, para crear, con `CurrentOrganization::run()`, así que no pide cambios de RLS por adelantado.

> **Nota (plan 017, 2026-11-11):** el acceso de plataforma es ahora el inicio de sesión único `/admin/login`; `/platform/login` solo redirige y la recuperación de contraseña es la de `/admin`. Donde estos criterios dicen «inicia sesión en `/platform/login`» (CA-016-1, CA-016-4 y CA-016-5) se entiende el acceso único; las pruebas lo ejercen así.

### DA-9 · Adelantar RC-7 — se recomienda **sí**

RC-7 aceptó vivir sin panel de plataforma hasta R5. Se adelanta porque soporte necesita ver y suspender organizaciones antes de una segunda organización y porque el único acceso hoy es la consola. Cerrada el 2026-11-10: se adelanta. Se actualiza `docs/plan-de-desarrollo.md` §3.4 (RC-7) en la fase F5.

## 4. Contrato

**Rutas web** (sin endpoints JSON; no se toca `docs/api/endpoints.md`): panel Filament `platform` en `/platform`, con `/platform/login` y la recuperación de contraseña; recursos `OrganizationResource` (lista, detalle, alta, acciones de suspensión y reactivación), `UserResource` (lista y detalle, solo lectura), `EventResource` (lista, solo lectura) y `AuditLogResource` (lista, solo lectura). Las acciones que cambian estado van por Livewire con CSRF.

**Configuración:** `config/platform.php` con `enabled` (variable `PLATFORM_PANEL_ENABLED`, por defecto `false` fuera de `local` y `testing`). Variable nueva: se añade a `.env.example` con valor ficticio y a `docs/operacion.md`. Se añade `platform` a `Organization::RESERVED_SLUGS`.

**Acceso:** `PlatformPanelProvider` (id `platform`, sin *tenant*), registrado en `bootstrap/providers.php`; el middleware `EnsurePlatformPanelEnabled` responde 404 en todo el panel (también en Livewire) si `config('platform.enabled')` es falso. Los avisos de contraseña y de dispositivo nuevo de un usuario de plataforma sin membresía apuntan a este panel (`User::preferredPanelId()`). `User::canAccessPanel()` decide **por panel**: `admin` exige membresía activa (sin cambios); `platform` exige `is_platform_admin`. Gate `platform.access` (denegar por defecto, sin `Gate::before`) para las pantallas de solo lectura de usuarios, eventos y bitácora.

**Policy:** `OrganizationPolicy` (nueva): `viewAny`, `view`, `create`, `suspend`, `reactivate`, todas solo para `is_platform_admin`. No se reutilizan `EventPolicy` ni las demás Policies de organización: abrir a plataforma un permiso en el contexto de una organización rompería CA-001-17.

**Form Requests (reglas reutilizadas por las Actions):** `CreateOrganizationRequest` (slug, nombre, correo y nombre del primer administrador; `CreateOrganization` las usa en lugar de las que tenía en línea) y `SuspendOrganizationRequest` (`reason` de 10 a 500 caracteres). Reactivar no recibe datos y no necesita Form Request.

**Actions:** `CreateOrganization` (existente; se reutiliza sin cambios de firma), `SuspendOrganization` y `ReactivateOrganization` (nuevas; idempotentes y auditadas). Las consultas de listas son clases de consulta delgadas del panel; las que tocan modelos con organización pasan por `withoutOrganizationScope()`.

**Propuesta de datos** (`docs/database-design.md` §6):

1. **Necesidad:** filtrar y mostrar la bitácora por organización con integridad e índice (DT-01); dueño: plataforma. Ciclo de vida: la bitácora se conserva 365 días (`activitylog`).
2. **Inspección:** `activity_log` es la tabla estándar de Spatie sin `organization_id`; el valor viaja en `properties->organization_id` en los registros hechos dentro de una organización. 5 filas locales.
3. **Modelo:** `activity_log.organization_id uuid NULL` con clave foránea a `organizations` (`restrictOnDelete`) e índice `(organization_id, created_at)`. Nula en los hechos de plataforma. Se escribe desde un modelo de actividad propio (`config('activitylog.activity_model')`) que toma el valor de `properties.organization_id` y, si no está, de `CurrentOrganization`.
4. **Integridad:** clave foránea; el relleno inicial toma solo valores que coinciden con una organización existente; el resto queda nulo.
5. **Cambio:** una migración que añade la columna, el índice y rellena desde `properties`; `down()` quita la columna y el índice (la información sigue en `properties`). La migración la aplica el humano (acción 1 de `AGENTS.md`).
6. **Alternativa descartada:** consultar `properties->>'organization_id'` sin columna: sin índice, sin integridad y obliga a repetir la expresión en cada consulta.
7. **Preguntas de negocio:** ninguna.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | **Plataforma** (`is_platform_admin`, con 2FA según DA-1): entrar al panel; ver organizaciones, usuarios, eventos y bitácora; crear, suspender y reactivar organizaciones. **`org_admin`, `organizer`, `reviewer`:** nada del panel de plataforma (403). **Visitante:** redirige al inicio de sesión. Un usuario de plataforma **no recibe** permisos de organización: sigue sin entrar a `admin` salvo por una membresía (CA-001-17) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `OrganizationPolicy` para las acciones sobre organizaciones y el Gate `platform.access` para las pantallas de solo lectura; pruebas en `tests/Feature/Platform/` con una prueba por rol y acción (permitido y denegado) |
| ¿Hay modelos nuevos con datos de una organización? | No hay modelos nuevos de datos de negocio. El modelo de actividad propio (`activity_log`) es de plataforma, con columna `organization_id` nula; se declara aparte de `BelongsToOrganization` y lo cubre una guardiana propia. Las listas de `Event` y de membresías usan `withoutOrganizationScope()` auditado, nunca un contexto fijado a mano |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Cruza organizaciones, no se apoya en `event_assignments`. Solo lee metadatos del evento (nombre, estado, fechas, idiomas, organización) |
| ¿Se guardan datos personales nuevos? | **No se guarda nada nuevo.** Cambia **quién ve** datos existentes: el encargado (la plataforma) ve nombre y correo de los miembros de los equipos y la bitácora. Base legal: relación de trabajo con la organización y soporte como encargado. IP y hash de correo de `auth` **no se muestran**. Se añade una nota a `docs/privacidad.md` §2 (fila de miembros del equipo) en el mismo commit |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No (ADR-0007 no aplica) |
| ¿Se envían correos o notificaciones? | Solo el existente de «fijar contraseña» al crear una organización (`CreateOrganization`, por cola). La suspensión no envía aviso (fuera) |
| ¿Qué se registra en la auditoría? | Alta, suspensión (con motivo) y reactivación de organizaciones (quién, cuándo, estado anterior y nuevo); cada consulta entre organizaciones (`organization_scope_bypassed`, con actor, modelo y panel); inicios de sesión y fallos del panel de plataforma (registro `auth`) |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Panel `platform` vacío: proveedor, `config/platform.php`, `.env.example`, inicio de sesión y recuperación, `canAccessPanel` por panel, `platform` reservado, pruebas de acceso (H-016-1) | M |
| F1 | `activity_log.organization_id`: migración propuesta, modelo de actividad propio, relleno y guardiana (cierra DT-01) | M |
| F2 | Organizaciones: lista, detalle, alta, suspensión y reactivación, `OrganizationPolicy`, efecto en el panel de la organización (H-016-2) | M |
| F3 | Usuarios y eventos de solo lectura con salto de filtro auditado (H-016-3 y H-016-4) | S |
| F4 | Visor de bitácora con redacción de `auth` y filtros (H-016-5) | S |
| F5 | Guardianas, i18n es y en, verificación a ~375 px y ~1280 px, documentación (`docs/seguridad.md`, `docs/privacidad.md`, `docs/operacion.md`, RC-7) y cierre de DT-01 | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-016-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

**H-016-1 · Acceso al panel**

- **CA-016-1:** *Dado* un usuario con `is_platform_admin` y sin membresías, *cuando* inicia sesión en `/platform/login` con credenciales válidas, *entonces* entra al panel de plataforma, y su dispositivo nuevo se registra y se avisa por correo, igual que en el panel de organizaciones.
- **CA-016-2:** *Dado* un usuario con membresía activa y sin `is_platform_admin` (`org_admin`, `organizer` o `reviewer`), *cuando* abre cualquier URL de `/platform` o invoca un componente del panel, *entonces* se deniega con 403 y no ve datos; *dado* un visitante sin sesión, *entonces* se le redirige al inicio de sesión de plataforma.
- **CA-016-3:** *Dado* un usuario de plataforma sin membresía en A, *cuando* abre `/admin` o el panel de A, *entonces* sigue sin acceso (regresión de CA-001-17); *dado* uno que además es miembro de A, *entonces* entra a `/admin` solo a A y a `/platform`.
- **CA-016-4:** *Dado* el inicio de sesión de `/platform/login`, *cuando* se superan 5 intentos fallidos en 15 minutos del mismo correo e IP, *entonces* se limita con el mismo mecanismo y mensaje que el panel de organizaciones y sin revelar si el correo existe.
- **CA-016-5:** *Dado* un usuario de plataforma, *cuando* solicita restablecer su contraseña desde `/platform/login`, *entonces* recibe el enlace por correo en cola, y *cuando* el correo no existe, *entonces* la respuesta es idéntica.
- **CA-016-6:** *Dado* `APP_ENV=production` sin `PLATFORM_PANEL_ENABLED`, *cuando* se pide `/platform`, *entonces* responde 404 (en la página y en las peticiones de Livewire); con la variable en `true`, o en `local` y `testing`, el panel responde.
- **CA-016-7:** *Dado* el alta de una organización, *cuando* el `slug` es `platform` (formulario o comando), *entonces* se rechaza por reservado.
- **CA-016-8:** *Dado* que la 2FA del plan 014 (H-014-1) exista, *cuando* un usuario de plataforma sin 2FA inicia sesión, *entonces* no accede a ninguna página de `/platform` hasta configurarla.
  Manual: se verifica al implementar H-014-1 (su prueba es CA-014-1); antes de habilitar `PLATFORM_PANEL_ENABLED` en producción, iniciar sesión con un usuario de plataforma sin 2FA y comprobar que `/platform` lo envía a configurarla.

**H-016-2 · Organizaciones**

- **CA-016-9:** *Dado* un usuario de plataforma y organizaciones activas y suspendidas, *cuando* abre Organizaciones, *entonces* ve todas con nombre, `slug`, estado, número de miembros activos y de eventos, paginadas con máximo 100.
- **CA-016-10:** *Dado* una organización, *cuando* se abre su detalle, *entonces* se ven sus administradores (nombre y correo), sus conteos y sus últimos registros de auditoría, y **no** propuestas, contactos ni archivos.
- **CA-016-11:** *Dado* un usuario de plataforma, *cuando* crea una organización con `slug`, nombre y administrador válidos, *entonces* se crea con su primer `org_admin` activo, se encola el correo de contraseña y queda auditado, igual que con `app:create-organization`.
- **CA-016-12:** *Dado* el alta, *cuando* el `slug` ya existe (incluso en una eliminada) o es reservado, o el correo no es válido, *entonces* se rechaza (el formato, el reservado y el correo, con el error de cada campo; el `slug` repetido, con un aviso) y no se crea nada.
- **CA-016-13:** *Dado* una organización activa, *cuando* plataforma la suspende con un motivo, *entonces* su estado pasa a `suspended` y queda auditado con quién, cuándo, motivo y estados anterior y nuevo.
- **CA-016-14:** *Dado* la suspensión, *cuando* el motivo falta o tiene menos de 10 caracteres, *entonces* se rechaza; *cuando* la organización ya está suspendida, *entonces* no cambia nada y no duplica el registro.
- **CA-016-15:** *Dado* una organización suspendida, *cuando* uno de sus miembros abre su panel o el sitio público, *entonces* el panel no la ofrece como *tenant*, su URL directa responde 404 y el sitio público responde 404; una sesión abierta pierde el acceso en la petición siguiente; los datos siguen intactos.
- **CA-016-16:** *Dado* una organización suspendida, *cuando* plataforma la reactiva, *entonces* vuelve a `active`, queda auditado y sus miembros recuperan el acceso.
- **CA-016-17:** *Dado* un `org_admin`, un `organizer` o un `reviewer`, *cuando* intenta crear, suspender o reactivar una organización, *entonces* se deniega con 403 y no cambia nada (una prueba por rol y acción).
- **CA-016-18:** *Dado* el panel, *cuando* se revisan sus acciones sobre organizaciones, *entonces* no existe ninguna que elimine una organización.

**H-016-3 · Usuarios**

- **CA-016-19:** *Dado* un usuario de plataforma, *cuando* abre Usuarios, *entonces* ve a los usuarios de todas las organizaciones con nombre, correo, si es de plataforma y número de organizaciones activas; busca por nombre o correo; está paginada con máximo 100.
- **CA-016-20:** *Dado* la lista y el detalle de usuarios, *cuando* se renderizan, *entonces* no contienen contraseña, hash, `remember_token`, secretos de 2FA ni códigos de recuperación.
- **CA-016-21:** *Dado* un usuario con membresía en A y en B, *cuando* se abre su detalle, *entonces* aparece una vez con ambas membresías (organización, rol y estado).
- **CA-016-22:** *Dado* el panel de usuarios, *cuando* se revisan sus formularios y acciones, *entonces* es de solo lectura y no existe forma de crear, editar, promover o quitar `is_platform_admin` (CA-001-16 se mantiene).

**H-016-4 · Eventos**

- **CA-016-23:** *Dado* eventos de varias organizaciones, *cuando* plataforma abre Eventos, *entonces* ve organización, nombre (idioma base), estado, fechas en la zona del evento e idiomas, con filtros por organización y estado, paginados con máximo 100; los eventos de una organización suspendida aparecen; los eliminados, no.
- **CA-016-24:** *Dado* la lista, *cuando* se revisan sus acciones, *entonces* es de solo lectura: no hay crear, editar, publicar ni archivar eventos de otra organización.
- **CA-016-25:** *Dado* un usuario de plataforma, *cuando* se carga una lista que atraviesa el filtro de organización, *entonces* se escriben registros `organization_scope_bypassed` por consulta (uno por cada vez que se arma la consulta; nunca uno por fila) con actor, modelo y panel, y la consulta no lanza `MissingOrganizationContext`; *dado* cualquier otro usuario, *entonces* el salto se deniega.

**H-016-5 · Auditoría**

- **CA-016-26:** *Dado* un usuario de plataforma, *cuando* abre Auditoría, *entonces* ve los registros de todas las organizaciones, los más recientes primero, con fecha (UTC), evento, descripción, actor, organización y objeto, filtrables por organización, actor, tipo de evento y rango de fechas, paginados con máximo 100.
- **CA-016-27:** *Dado* un registro `auth`, *cuando* se muestra, *entonces* solo aparecen evento, fecha y actor: ni la IP ni el hash del correo; los demás registros muestran los valores anterior y nuevo.
- **CA-016-28:** *Dado* el visor, *cuando* se revisan sus acciones, *entonces* es de solo lectura: no se edita ni se borra ninguna entrada.
- **CA-016-29:** *Dado* un hecho auditado dentro de una organización, *cuando* se guarda, *entonces* `activity_log.organization_id` queda con esa organización; un hecho de plataforma queda nulo; y la migración rellena los registros existentes desde `properties` solo cuando coinciden con una organización existente (cierra DT-01).
- **CA-016-30:** *Dado* el visor, *cuando* se filtran los registros `organization_scope_bypassed`, *entonces* se ve quién consultó qué modelo y cuándo (el acceso entre organizaciones es auditable por la propia plataforma).

**Transversales**

- **CA-016-31:** *Dado* el código de `app/Filament/Platform/`, *cuando* corre la guardiana, *entonces* ninguna clase consulta un modelo con `BelongsToOrganization` sin `withoutOrganizationScope()`, y ninguna referencia `Contact`, propuestas ni archivos de propuestas; `TenantBypassTest` sigue pasando.
- **CA-016-32:** *Dado* `lang/es` y `lang/en`, *cuando* corre la prueba de paridad, *entonces* las claves del panel de plataforma coinciden.
- **CA-016-33:** El panel se usa con teclado, muestra de forma visible que es la «Plataforma», tiene contraste AA y no da errores en consola.
  Manual: con un usuario de plataforma, abrir `/platform` y recorrer Organizaciones, Usuarios, Eventos y Auditoría a ~375 px y ~1280 px, con Tab, comprobando la marca «Plataforma» y la consola del navegador.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Un usuario de plataforma ve datos de una organización por una consulta mal escrita | Panel sin *tenant*: sin contexto, una consulta a un modelo con organización falla cerrada; guardiana CA-016-31; solo metadatos (DA-4); revisión humana de tenancy antes de aprobar |
| El panel queda en producción sin 2FA | Apagado por defecto fuera de `local` y `testing` (DA-1, CA-016-6); CA-016-8 como requisito de habilitación |
| Un `org_admin` llega al panel de plataforma | `canAccessPanel()` por panel, Gate `platform.access` y `OrganizationPolicy` (CA-016-2 y CA-016-17); 403 en Livewire |
| Una Policy de organización concede acceso a plataforma sin querer | No se reutilizan; plataforma no recibe permisos de organización (CA-016-3) |
| El visor de bitácora expone IP o correos | Redacción de `auth` (CA-016-27); sin PII nueva |
| Exceso de registros de salto de filtro (uno por consulta) | Un registro por consulta y no por fila (CA-016-25); la limpieza de `activitylog` (365 días) los cubre; revisar volumen tras F3 |
| Suspender no detiene a un usuario con sesión abierta | La comprobación se hace por petición (CA-016-15), no solo al iniciar sesión |
| La migración de `activity_log` rellena mal la organización | Solo se rellena si el valor coincide con una organización existente; `down()` conserva `properties` (CA-016-29) |
| RLS (plan 014) deja el panel sin datos | DA-8: el plan 014 define el contexto de plataforma; hasta entonces no hay RLS |
| Confundir el panel de plataforma con el de una organización | Marca visible «Plataforma» y panel y ruta separados (DA-2, CA-016-33) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| F0 a F5 | `e1a0f02` | 2026-11-10 | Un solo commit para las seis fases (se construyeron seguidas, no por separado). Pruebas de plataforma, arquitectura, aislamiento y paridad de idiomas en verde (85 en la última corrida enfocada); Larastan sin errores en los archivos del plan; `composer check:criteria` sin errores. Verificado en el navegador a 1280 px (organizaciones y auditoría) y a 375 px (auditoría), sin errores de consola. Pendiente a mano: CA-016-8 (con la 2FA del plan 014) y CA-016-33 en Usuarios, Eventos y el detalle de una organización. La migración `2026_11_10_650000` ya está aplicada en la base local. |
