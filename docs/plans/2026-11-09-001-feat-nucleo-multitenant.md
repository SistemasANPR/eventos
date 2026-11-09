# feat: núcleo multi-tenant (organizaciones, contexto y aislamiento)

> Primer plan del proyecto. Es la base de todo lo demás y sirve de ejemplo del formato. Ajustar con las decisiones de los ADR 0003 y 0005 antes de aprobarlo.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** L
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

Dejar listo el aislamiento entre organizaciones **antes** de construir cualquier módulo: modelo de organización y membresía, contexto actual, scope global que falla cerrado, asignación automática al crear y las pruebas guardianas funcionando con modelos reales.

**Dentro:** modelos `Organization` y membresía de usuarios; `BelongsToOrganization`; `CurrentOrganization`; `MissingOrganizationContext`; resolución del contexto en el panel de Filament; roles por organización; bitácora de auditoría para el acceso sin scope; pruebas guardianas; un modelo de ejemplo con datos de organización para ejercitarlas.

**Fuera:** eventos (`Event`), propuestas, registro, pagos, sitio público, RLS de PostgreSQL (si DA-2 del ADR-0003 lo difiere, queda como deuda P1) y cualquier módulo de negocio.

## 2. Punto de partida

[RELLENAR al iniciar: versión de Laravel instalada, kit de inicio elegido, paquetes instalados, estado de `composer check` y `npm run build`.]

## 3. Decisiones

- **DA-1 · Alcance de los roles** — se recomienda **organización como *team* de `spatie/laravel-permission`** y `event_assignments` para acceso por evento (ADR-0003 DA-1).
- **DA-2 · RLS de PostgreSQL** — se recomienda **incluirla ya** como segunda barrera (ADR-0003 DA-2). Si se difiere, anotar deuda P1.
- **DA-3 · Personas y membresías** — se recomienda `User` con tabla `organization_user` (rol por organización) y datos de contacto por organización (ADR-0003 DA-3).
- **DA-4 · Resolución del contexto** — se recomienda tenant de Filament para los paneles y subdominio para el sitio público (ADR-0003 DA-4).

## 4. Contrato

- Tablas: `organizations`, `organization_user` y las de roles y permisos del paquete; `activity_log`. Todas con UUID; `organization_id` `NOT NULL` con clave foránea en lo que lo requiera. Propuesta de datos según `docs/database-design.md` §6 antes de escribir migraciones.
- Clases: `App\Models\Organization`, `App\Models\Concerns\BelongsToOrganization`, `App\Support\Tenancy\CurrentOrganization`, `OrganizationScope` y `App\Support\Tenancy\Exceptions\MissingOrganizationContext`.
- Sin rutas JSON públicas en este plan.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| Roles | Plataforma (soporte), administrador de organización, miembro. Los roles de evento llegan con el plan de eventos |
| Policy | `OrganizationPolicy` y política de membresías; prueba por rol (permitido y denegado) |
| Modelos con tenant | Un modelo de ejemplo (`Contact`) que usa `BelongsToOrganization` para ejercitar las guardianas |
| Datos personales | Nombre y correo de usuarios; base legal y retención en `docs/privacidad.md` |
| Archivos | No aplica |
| Dinero | No aplica |
| Correos | Invitación a una organización (cola, plantilla en `lang/`) |
| Auditoría | Cambios de rol, invitaciones y todo uso de `withoutOrganizationScope()` |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Instalar paquetes autorizados, configurar `Model::shouldBeStrict()`, activar las guardianas en verde con cero modelos | S |
| F1 | `Organization`, membresías, `CurrentOrganization`, `BelongsToOrganization`, scope, excepción y modelo de ejemplo | M |
| F2 | Roles por organización, Policies y panel de Filament con tenant | M |
| F3 | `withoutOrganizationScope()` con autorización y auditoría; `CurrentOrganization::run()` para jobs | S |
| F4 | RLS de PostgreSQL (si DA-2 lo incluye) y prueba de acceso negado | M |

## 7. Criterios de aceptación

- **CA-001-1:** *Dado* registros de las organizaciones A y B, *cuando* el contexto es A, *entonces* cualquier consulta de un modelo con tenant devuelve solo los de A.
- **CA-001-2:** *Dado* que no hay contexto de organización, *cuando* se consulta un modelo con tenant, *entonces* se lanza `MissingOrganizationContext`.
- **CA-001-3:** *Dado* el contexto A, *cuando* se crea un registro sin `organization_id`, *entonces* se le asigna A.
- **CA-001-4:** *Dado* el contexto A, *cuando* se intenta crear un registro con el `organization_id` de B, *entonces* se rechaza.
- **CA-001-5:** *Dado* un identificador de un registro de B, *cuando* se accede desde una ruta con binding acotado en el contexto A, *entonces* la respuesta es 404.
- **CA-001-6:** *Dado* un usuario sin el rol de plataforma, *cuando* llama a `withoutOrganizationScope()`, *entonces* se deniega; con el rol, se permite y queda registro de auditoría.
- **CA-001-7:** *Dado* un job o comando, *cuando* ejecuta `CurrentOrganization::run($org, fn)`, *entonces* el contexto se establece durante la ejecución y se restaura después, incluso si lanza una excepción.
- **CA-001-8:** *Dado* un usuario con membresía solo en A, *cuando* intenta cambiar el tenant a B en el panel, *entonces* se deniega.
- **CA-001-9:** *Dado* un mismo `slug`, *cuando* se crea en dos organizaciones distintas, *entonces* se permite; *cuando* se repite en la misma, *entonces* se rechaza.
- **CA-001-10:** *Dado* un administrador de A, *cuando* gestiona usuarios, *entonces* solo ve y modifica los de A (una prueba por rol y acción, permitido y denegado).
- **CA-001-11:** *Dado* cualquier modelo de `app/Models`, *cuando* corren las guardianas, *entonces* `ModelsAreScopedTest` y `TenantIsolationTest` pasan.
- **CA-001-12:** *Dado* el acceso directo a la base como el rol de la aplicación (si DA-2 lo incluye), *cuando* se consulta una tabla con tenant sin `app.organization_id`, *entonces* no se devuelven filas.
  Manual: ejecutar la consulta en `psql` con el usuario de la aplicación y comprobar cero filas.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Olvidar el contexto en jobs, comandos y seeders | La excepción hace evidente el olvido; `CurrentOrganization::run()` y pruebas por job |
| El scope global sorprende en consultas de plataforma | `withoutOrganizationScope()` con auditoría, y lista cerrada de usos en el ADR-0003 |
| Una fábrica no acepta `organization_id` explícito | La guardiana falla con un mensaje claro; es parte de la definición de terminado |
| RLS mal configurado bloquea consultas legítimas | Pruebas de acceso permitido y denegado; despliegue gradual en staging |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
