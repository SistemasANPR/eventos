# ADR-0003: Multi-tenancy — una base de datos, organización y evento

- **Fecha:** 2026-11-09
- **Estado:** Propuesta (cerrar las decisiones abiertas antes de aceptar)
- **Alcance:** modelo de datos, consultas, autorización, cachés, colas y archivos

## Contexto

La plataforma gestiona eventos de la ANPR y se ofrecerá a otras organizaciones. Una fuga de datos entre organizaciones es el peor error posible. En el cpanel de origen, las ediciones del congreso se mezclaban por valores de evento escritos a mano en el código y por consultas que ignoraban el evento. Hace falta una jerarquía clara, aislamiento por defecto y reportes que crucen eventos de una misma organización (CRM de comunidad).

## Decisión

1. **Jerarquía:** `Organization` (tenant) → `Event` → datos del evento. Los datos de personas y organizaciones externas (ponentes, patrocinadores) pertenecen a la **organización**, no al evento, para que se reutilicen entre ediciones.
2. **Una sola base de datos**, con `organization_id` `NOT NULL` y clave foránea en toda tabla con datos de una organización. Nada de una base por organización.
3. **Contrato en código** (lo implementa el plan `001-nucleo-multitenant`):
   - `App\Models\Concerns\BelongsToOrganization`: añade un scope global, asigna `organization_id` desde el contexto al crear y expone `withoutOrganizationScope()`.
   - `App\Support\Tenancy\CurrentOrganization`: `set()`, `get()`, `forget()` y `run($org, $callback)`. El contexto se resuelve desde el panel de Filament (tenant), el dominio o la sesión.
   - `App\Support\Tenancy\Exceptions\MissingOrganizationContext`: **sin contexto, la consulta lanza esta excepción.** Falla cerrada y visible; nunca devuelve vacío ni devuelve todo.
4. **Jobs, comandos y seeders** no tienen contexto: lo establecen con `CurrentOrganization::run()`.
5. **`withoutOrganizationScope()`** solo en estos casos, con autorización de rol de plataforma y registro de auditoría: soporte con acceso concedido, agregados de la plataforma (métricas globales) y migraciones de datos. Cualquier otro uso es la acción 10 de `AGENTS.md`.
6. **El evento es un ámbito explícito, no global:** los modelos de un evento llevan `event_id` y se consultan con `forEvent($event)`. No hay scope global de evento porque los reportes cruzan eventos. El evento nunca se deduce de un valor fijo.
7. **Claves únicas** por ámbito (`organization_id` + clave de negocio). **Route-model binding acotado** (`scopeBindings`): un identificador de otra organización responde 404. Cachés, colas y rutas de archivos incluyen la organización.
8. **Pruebas guardianas** (`tests/Feature/Tenancy/`): todo modelo es con tenant o global declarado, y una organización nunca ve datos de otra.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Base de datos (o esquema) por organización | Mantenimiento y migraciones multiplicados; reportes cruzados imposibles. Reconsiderar solo si un cliente exige aislamiento físico |
| Filtro manual en cada consulta | Un olvido es una fuga. El scope global falla cerrado |
| Paquete de tenancy de terceros | Añade una dependencia grande para algo que el contrato anterior resuelve en poco código; reevaluar si crece la complejidad |
| Scope global también para el evento | Rompe reportes entre eventos y complica el CRM |

## Consecuencias

**Positivas:** aislamiento por defecto y comprobado por pruebas; reportes y CRM entre eventos; un solo despliegue y una sola migración.

**Negativas o riesgos:** hay que recordar establecer el contexto en jobs y comandos (la excepción lo hace evidente); el scope global puede sorprender en consultas de plataforma (por eso existe `withoutOrganizationScope()` con auditoría).

**Qué cambia para el equipo:** todo modelo nuevo usa `BelongsToOrganization` o se declara global; toda fábrica acepta `organization_id`.

## Decisiones abiertas

- **DA-1: alcance de los roles.** ¿Organización o evento? Se recomienda **roles a nivel de organización** (`spatie/laravel-permission` con la organización como *team*) y el acceso a eventos concretos mediante una tabla `event_assignments (user_id, event_id, role)` consultada por las Policies. Los evaluadores y ponentes suelen ser de un solo evento; los administradores, de toda la organización.
- **DA-2: seguridad a nivel de fila de PostgreSQL (RLS).** Se recomienda **sí, como segunda barrera** desde la primera versión: `SET LOCAL app.organization_id` por transacción y políticas por tabla. Cuesta un poco más de configuración y protege contra un fallo del scope. Si se difiere, registrarlo como deuda P1.
- **DA-3: ¿personas globales entre organizaciones?** Se recomienda que una persona (`User`) pueda pertenecer a varias organizaciones (membresía), mientras que sus datos de contacto dentro de cada organización (`Contact`) son de esa organización. Evita fugas de datos personales entre clientes.
- **DA-4: resolución del contexto en el sitio público.** ¿Dominio propio por organización, subdominio o ruta (`/o/{slug}`)? Se recomienda **subdominio por defecto y dominio propio opcional**.

## Referencias

- `app/CLAUDE.md` §2, `docs/seguridad.md`, `docs/database-design.md` §3
- `tests/Feature/Tenancy/`
