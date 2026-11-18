# Eventos

Plataforma SaaS multi-organización para gestionar eventos: convocatoria y envío de propuestas, evaluación por comité, decisiones y comunicación con los participantes.

> Estado: en desarrollo (rama `staging`). `main` es producción. Plan vigente en [`docs/plan-de-desarrollo.md`](docs/plan-de-desarrollo.md).

## Principios

Cada cambio protege, en este orden:

1. **Aislamiento entre organizaciones:** una organización nunca ve ni modifica datos de otra.
2. **Autorización en el servidor:** ocultar un botón nunca basta.
3. **Integridad de pagos y datos personales.**

## Arquitectura

Monolito Laravel con tres zonas de interfaz ([ADR-0004](docs/adr/0004-tres-zonas-de-interfaz.md)):

| Zona | Quién | Interfaz |
|---|---|---|
| Panel de organizadores | Equipo de cada organización | Filament |
| Portales con sesión | Ponentes, evaluadores, patrocinadores, voluntarios | Filament, un panel por rol |
| Sitio público | Cualquier visitante | React + Inertia (SSR) |

Jerarquía de datos: **Organización (tenant) → Evento → todo lo demás** ([ADR-0003](docs/adr/0003-multi-tenancy-organizacion-y-evento.md)).

**Stack:** PHP 8.4, Laravel 13, Filament 5, Inertia + React + TypeScript + Tailwind, PostgreSQL 18, Redis y Mailpit (correo local).

## Puesta en marcha

Requisito: Docker Desktop (con WSL2 en Windows). Todo corre en el contenedor `app`; no se usa el PHP, Composer ni Node del equipo.

```bash
docker compose up -d                                 # levanta app, pgsql, redis y mailpit
docker compose exec app composer install
docker compose exec app cp .env.example .env
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed   # base local con datos ficticios
docker compose exec app npm install
docker compose exec app npm run dev                  # Vite con recarga en caliente
```

Si un puerto ya está ocupado en tu máquina, cámbialo en `.env` (`APP_PORT`, `FORWARD_DB_PORT`).

| Servicio | Dirección local |
|---|---|
| Aplicación | http://localhost:8080 |
| Vite | http://localhost:5173 |
| Mailpit (correos enviados) | http://localhost:8025 |
| PostgreSQL | `localhost:5433` (`pgsql:5432` entre contenedores) |

Guía completa y problemas frecuentes en [`docs/entorno-local.md`](docs/entorno-local.md).

## Verificación

```bash
docker compose exec app composer check       # lint + análisis estático + pruebas + criterios
docker compose exec app composer test        # Pest
docker compose exec app npm run typecheck
docker compose exec app npm run test
```

## Cómo se trabaja

- Desarrollo guiado por especificaciones: especificación → contrato → pruebas → código → verificación ([ADR-0001](docs/adr/0001-desarrollo-guiado-por-especificaciones.md)). Los planes viven en [`docs/plans/`](docs/plans/).
- Ramas: se desarrolla en `staging`; `main` no se toca ([ADR-0008](docs/adr/0008-entorno-local-docker-y-ramas.md)).
- Commits: Conventional Commits, scope en inglés, descripción en español sin tildes.
- Reglas para agentes de código: [`AGENTS.md`](AGENTS.md) y [`CLAUDE.md`](CLAUDE.md).

## Documentación

| Tema | Documento |
|---|---|
| Decisiones de arquitectura | [`docs/adr/`](docs/adr/) |
| Datos persistentes | [`docs/database-design.md`](docs/database-design.md) |
| Seguridad | [`docs/seguridad.md`](docs/seguridad.md) |
| Privacidad | [`docs/privacidad.md`](docs/privacidad.md) |
| API y webhooks | [`docs/api/endpoints.md`](docs/api/endpoints.md) |
| Operación y despliegue | [`docs/operacion.md`](docs/operacion.md) |
| Convenciones | [`docs/convenciones.md`](docs/convenciones.md) |
| Glosario del dominio | [`docs/producto/glosario.md`](docs/producto/glosario.md) |
| Deuda técnica | [`docs/sprints/deuda-tecnica.md`](docs/sprints/deuda-tecnica.md) |

## Licencia

Software libre bajo la [licencia MIT](LICENSE).
