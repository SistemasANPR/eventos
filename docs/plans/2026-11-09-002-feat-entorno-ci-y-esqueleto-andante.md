# feat: entorno Docker, CI y esqueleto andante

> Épica E-01 de `docs/plan-de-desarrollo.md` (R0). Deja el proyecto Laravel corriendo en Docker, el CI en verde en `staging` y `main`, un recorrido mínimo de punta a punta (organización → evento → página pública), ese esqueleto desplegado en Railway `staging` y los documentos de la plantilla adaptados.

- **Fecha:** 2026-11-09
- **Estado:** En curso
- **Esfuerzo:** M
- **Deuda relacionada:** SSR de Inertia diferido (RC-5, ADR-0004 DA-1) hasta antes de R3; se registrará en `docs/sprints/deuda-tecnica.md` al aprobar el ADR correspondiente.

## 1. Objetivo y alcance

Que cualquiera del equipo levante todo con un comando, que cada cambio pase por un CI que bloquee lo roto, y que exista desde la primera semana un recorrido mínimo funcionando en un entorno real, para que el riesgo de integración aparezca pronto y no el último día.

**Dentro:** creación del proyecto Laravel con el kit de inicio React (Inertia), PostgreSQL, Pest y Sail con el servicio `app` (ADR-0008 DA-1); puertos locales 8080 y 5433; Mailpit; versiones de PHP y Node fijadas; **scripts de Composer y npm** que las reglas dan por existentes; CI en pull requests, `staging` y `main`; protección de ramas; la **migración final de `events`** (la definida por el plan 003) y su modelo; la **página pública del evento publicado** (con fechas, zona y sede) con Inertia **sin SSR**; un *seeder* de datos ficticios solo para local y `staging`; despliegue del esqueleto a Railway `staging`; adaptación de los marcadores de la plantilla y de los ADR 0003, 0004, 0005 y 0008.

**Fuera:** producción (plan 010); gestión del evento desde el panel, publicación y aviso de privacidad (plan 003); SSR (R3); cualquier módulo de negocio; datos reales.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-002-1 | Como desarrollador, quiero levantar todo con un comando y tener los scripts de calidad, para trabajar con paridad con producción | M | — |
| H-002-2 | Como líder, quiero CI en verde en `staging` y `main` con ramas protegidas, para no integrar código roto | S | H-002-1, A8 |
| H-002-3 | Como visitante, quiero ver la página pública de un evento publicado, para validar el recorrido completo (esta historia absorbe a la antigua H-003-4) | M | H-002-1, H-001-1 |
| H-002-4 | Como líder, quiero el esqueleto desplegado en Railway `staging`, para probar en un entorno real | S | H-002-2, H-002-3, A4 |
| H-002-5 | Como líder, quiero los documentos de la plantilla y los ADR de R0 adaptados, para no salir con marcadores sin rellenar ni decisiones en «Propuesta» | S | H-002-1 |

Si H-002-3 resulta mayor que M al absorber la página pública, se parte con tu aprobación en «recorrido mínimo» y «página completa»; no se avanza como una historia mayor.

## 2. Punto de partida

Medido el 2026-11-09: el repositorio tiene la plantilla en la raíz y la rama de trabajo es `staging` (commit `0098bc0`); no hay `composer.json`, `package.json`, `artisan`, `Dockerfile` ni `docker-compose.yml`. El PHP del equipo (8.1, de XAMPP) no sirve para un Laravel actual, así que el proyecto se crea desde un contenedor temporal. En esta máquina el puerto 80 lo ocupa un servicio de Windows y el 5432 el contenedor `postgres-db-parksys` de otro proyecto, que no se toca (`docs/entorno-local.md`). El remoto de GitHub está vacío: no hay CI ni ramas protegidas hasta el primer push (acción A8, que ordena Antonio). Versiones de PHP, Node y Laravel: `[PENDIENTE: se registran al cerrar H-002-1]`.

## 3. Decisiones

### DA-1 · Base del entorno Docker — se recomienda **Laravel Sail con el servicio renombrado a `app`**

Cierra ADR-0008 DA-1. Alternativa: un `docker-compose.yml` propio. Las opciones vigentes del instalador y de Sail se verifican en la documentación oficial al crear el proyecto `[PENDIENTE: comando y opciones vigentes]`.

### DA-2 · Ubicación del repositorio — se recomienda **quedarse en `C:\`**

Cierra ADR-0008 DA-2 para el plazo del 18-nov: se acepta la lentitud de `composer install`, `npm install` y las pruebas. Se reevalúa tras R0.

### DA-3 · `staging` desplegado — se recomienda **desplegarlo en Railway el día 3**

Cierra ADR-0008 DA-3: la ANPR debe ver la convocatoria y aprobar el aviso de privacidad antes del lanzamiento.

### DA-4 · SSR y marca blanca — se recomienda **Inertia sin SSR en R0 y marca blanca fuera de R0**

Cierra ADR-0004 DA-1 (SSR) para R0 y fija ADR-0004 DA-4 (marca blanca) como fuera de R0: evita un proceso Node en producción durante la semana crítica. La página lleva metadatos básicos; SSR antes de R3.

### DA-5 · Protección de ramas — se recomienda **proteger `main` (pull request, CI y una revisión) y exigir CI en `staging`**

Cierra ADR-0008 DA-4; depende del plan de GitHub de la organización `[PENDIENTE: que el plan de GitHub permita proteger ramas]`.

### DA-6 · Migración de `events` — se recomienda **crearla aquí ya en su forma final**

El plan 003 define el modelo definitivo de `events`; este plan crea esa migración completa (no una versión mínima) y nadie la edita después, porque una migración aplicada no se edita (`docs/database-design.md` §5). El plan 003 solo añade Actions, Policies y pantallas sobre ella.

## 4. Contrato

- **Entorno:** `docker-compose.yml` con servicios `app`, `pgsql`, `redis` y `mailpit`; Vite en 5173; aplicación en `http://localhost:8080`; PostgreSQL en `localhost:5433`. Cada archivo `.env*` se trata como secreto; `.env.example` solo con valores ficticios.
- **Comandos** (se crean en H-002-1; deben existir en `composer.json` y `package.json`): `composer check`, `test`, `analyse`, `lint`, `format`, `test:coverage`, `check:criteria`; `npm run typecheck`, `lint`, `test`, `build`.
- **CI** (`.github/workflows/ci.yml`): se ejecuta en pull requests y en pushes a `staging` y `main`, con PostgreSQL de servicio, Pint, Larastan, Pest, `check:criteria`, `composer audit`, `npm audit`, búsqueda de secretos y el *build* del frontend.
- **Ruta web pública:** `GET /{organization:slug}/events/{event:slug}` (Inertia, sin SSR). El middleware `SetOrganizationFromRoute` del plan 001 establece el contexto antes del binding; un evento no publicado, de otra organización o con organización inexistente responde 404. Es la única definición de esta ruta; los planes 003 a 007 cuelgan sus rutas de ella.
- **Sin endpoints JSON ni webhooks nuevos**; solo `/up` de salud.
- **Propuesta de datos (`docs/database-design.md` §6):**
  - *Necesidad:* una tabla de eventos por organización que el esqueleto necesita ya y que el plan 003 gestiona; dueño: eventos.
  - *Modelo:* `events` en su forma final (UUID; `organization_id`; `name` y `description` traducibles; `slug`; `type`; `starts_at` y `ends_at` en UTC; `timezone` IANA; `venue_name`, `venue_address`, `city`; `locales` jsonb; `default_locale`; `currency`; `status`; `deleted_at`). Ámbito: organización.
  - *Integridad:* `organization_id uuid NOT NULL` con clave foránea; `unique(organization_id, slug)`; `unique(id, organization_id)` para claves foráneas compuestas de las tablas hijas; `CHECK` de `status`, `type`, `currency` y `ends_at >= starts_at`; `SoftDeletes`.
  - *Cambio:* migración nueva sin datos previos; la genera el agente y solo una persona la aplica contra una base real.
  - *Alternativa descartada:* un modelo de evento mínimo que luego se edite en el plan 003, por la regla de que una migración aplicada no se edita y por el costo de la migración doble.
  - *Preguntas de negocio:* ninguna.
- **Seeder de demostración:** crea una organización y un evento ficticios solo en local y `staging`; se niega a correr con `APP_ENV=production`.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | La página pública es de lectura anónima de eventos publicados. El resto (CI, despliegue, aplicación de migraciones en `staging`) es de Antonio como dueño de ramas y secretos |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No hay acciones autenticadas nuevas. La visibilidad de un evento publicado se prueba en una prueba de funcionalidad de la ruta pública (`tests/Feature/Public/`) |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | `Event` usa `BelongsToOrganization` (plan 001) y está cubierto por las guardianas |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No |
| ¿Se guardan datos personales nuevos? | No. Los datos locales y de `staging` son ficticios (`docs/privacidad.md`) |
| ¿Se suben archivos? | No aplica |
| ¿Hay dinero, pagos o webhooks? | No aplica (`currency` solo guarda `MXN` en R0, ADR-0005 DA-1) |
| ¿Se envían correos o notificaciones? | No. Mailpit local queda listo para los planes siguientes |
| ¿Qué se registra en la auditoría? | Nada nuevo; los despliegues los registra el proveedor y el CI |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Crear el proyecto Laravel en contenedor temporal (lo ejecuta una persona), instalar Sail con servicio `app`, fijar puertos y versiones, copiar la plantilla encima sin sobrescribir `composer.json`, `package.json` ni `.gitignore`, y **crear los scripts de Composer y npm** (H-002-1) | M |
| F1 | CI en verde y protección de ramas tras el primer push (H-002-2) | S |
| F2 | Migración final de `events`, modelo, seeder de demostración, ruta pública con Inertia sin SSR, claves i18n (H-002-3) | M |
| F3 | Proyecto de Railway `staging` con web, worker, PostgreSQL y Redis; despliegue desde `staging`; Antonio aplica las migraciones (H-002-4) | S |
| F4 | Marcadores de la plantilla y ADR adaptados (H-002-5) | S |

Las migraciones las genera el agente y solo una persona las aplica; instalar dependencias lo ejecuta una persona (acciones 1 y 4 de `AGENTS.md`).

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-002-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

### H-002-1 · Entorno con un comando

- **CA-002-1:** *Dado* un clon limpio del repositorio con Docker Desktop, *cuando* se ejecuta `docker compose up -d`, *entonces* los servicios `app`, `pgsql`, `redis` y `mailpit` quedan sanos y la aplicación responde en `http://localhost:8080`.
  Manual: ejecutar `docker compose up -d`, abrir `http://localhost:8080` y `http://localhost:8025`; confirmar respuesta y que `docker compose ps` no muestra servicios caídos.
- **CA-002-2:** *Dado* el entorno levantado, *cuando* se ejecuta `docker compose exec app composer check`, *entonces* termina con código 0 y muestra la salida real de lint, análisis, pruebas y criterios.
- **CA-002-3:** *Dado* un puerto ocupado (80, 5432), *cuando* se levanta el entorno, *entonces* usa 8080 y 5433 por `.env` y no detiene ni modifica el contenedor `postgres-db-parksys`.
  Manual: con `postgres-db-parksys` corriendo, levantar el entorno y comprobar que sigue intacto.
- **CA-002-4:** *Dado* el repositorio, *cuando* se revisa, *entonces* todo comando documentado en `AGENTS.md` existe en `composer.json` o `package.json`, no hay `.env` versionado y `.env.example` solo contiene valores ficticios.
- **CA-002-5:** *Dado* el hook de formato de `.claude/settings.json`, *cuando* el contenedor `app` no está levantado, *entonces* falla sin bloquear la edición (comportamiento documentado).
  Manual: apagar `app`, editar un archivo PHP y comprobar que la edición no se bloquea.
- **CA-002-6:** *Dado* el contenedor `app`, *cuando* se ejecutan `php -v` y `node -v`, *entonces* coinciden con las versiones fijadas en el punto de partida de este plan y en `composer.json` y el `Dockerfile`.
- **CA-002-7:** *Dado* el proyecto creado, *cuando* se compara con la plantilla, *entonces* `AGENTS.md`, `CLAUDE.md`, `docs/`, `tests/Arch`, `scripts/` y `.github/` siguen intactos y `composer.json`, `package.json` y `.gitignore` no fueron sobrescritos.
  Manual: revisar `git status` y `git diff` tras copiar la plantilla.

### H-002-2 · CI y ramas protegidas

- **CA-002-8:** *Dado* un push a `staging` o `main`, o un pull request, *cuando* corre el CI, *entonces* ejecuta lint, análisis estático, pruebas, `check:criteria`, `composer audit`, `npm audit`, búsqueda de secretos y *build*, y falla si cualquiera falla.
- **CA-002-9:** *Dado* un cambio que rompe una prueba, *cuando* se abre el pull request, *entonces* el CI queda en rojo y bloquea la integración.
- **CA-002-10:** *Dado* un secreto de prueba incluido en un archivo versionado, *cuando* corre el CI, *entonces* la búsqueda de secretos falla.
- **CA-002-11:** *Dado* la protección de ramas, *cuando* alguien intenta integrar a `main` sin pull request, sin CI en verde o sin una revisión, *entonces* GitHub lo rechaza; en `staging` exige CI en verde.
  Manual: intentar un push directo a `main` con una cuenta sin permisos de administrador y comprobar el rechazo.
- **CA-002-12:** *Dado* el CI, *cuando* se revisan sus variables, *entonces* no contiene credenciales en el repositorio y los secretos viven en los secretos de GitHub.

### H-002-3 · Página pública del evento (esqueleto andante)

- **CA-002-13:** *Dado* una organización con un evento publicado, *cuando* un visitante abre `/{organization:slug}/events/{event:slug}`, *entonces* responde 200 sin sesión y ve el nombre y la descripción en su idioma, las fechas en la zona horaria del evento (formateadas con `Intl`) y la sede.
- **CA-002-14:** *Dado* un evento en borrador, archivado o de otra organización, o una organización inexistente, *cuando* se abre su ruta pública, *entonces* responde 404.
- **CA-002-15:** *Dado* la página pública, *cuando* se inspecciona la respuesta, *entonces* no expone identificadores internos, correos del equipo ni datos de otras organizaciones, y los textos visibles salen de las claves de i18n (es y en).
- **CA-002-16:** *Dado* la página pública, *cuando* se visualiza a ~375 px y ~1280 px, *entonces* es legible, accesible con teclado, con contraste AA y sin errores en la consola.
  Manual: abrir la ruta a 375 px y 1280 px con la consola abierta, recorrerla con el teclado y comprobar estados de carga, vacío y error; revisar fechas y números en es y en.
- **CA-002-17:** *Dado* el *build* del frontend, *cuando* se ejecutan `npm run typecheck`, `lint`, `test` y `build`, *entonces* pasan sin `any` ni supresiones.
- **CA-002-18:** *Dado* el *seeder* de demostración, *cuando* se ejecuta en local o `staging`, *entonces* crea una organización y un evento ficticios; *cuando* se ejecuta con `APP_ENV=production`, *entonces* se niega y no escribe nada.
- **CA-002-19:** *Dado* la migración de `events`, *cuando* se inspecciona el esquema en PostgreSQL, *entonces* existen `unique(organization_id, slug)` y `unique(id, organization_id)` y los `CHECK` de estado, tipo, moneda y fechas; y *cuando* una tabla de prueba con clave foránea compuesta `(event_id, organization_id)` intenta referenciar un evento de otra organización, *entonces* la base lo rechaza.

### H-002-4 · Esqueleto desplegado en Railway `staging`

- **CA-002-20:** *Dado* el entorno `staging` de Railway, *cuando* Antonio aplica las migraciones (`migrate --force`, acción humana) antes de abrir la página, *entonces* `migrate:status` no muestra migraciones pendientes y el *seeder* de demostración se ejecuta con datos ficticios.
  Manual: ejecutar `migrate:status` en `staging` tras aplicar las migraciones y anotar la salida en el registro de avance.
- **CA-002-21:** *Dado* un push a `staging` con el CI en verde, *cuando* se despliega, *entonces* `/up` responde 200 en el entorno `staging` de Railway y la página pública del evento ficticio carga.
  Manual: abrir la URL de `staging`, comprobar `/up` y la página del evento.
- **CA-002-22:** *Dado* el entorno `staging`, *cuando* se revisan sus variables, *entonces* están definidas en el proveedor (sin valores en el repositorio) y `APP_DEBUG` está apagado.
- **CA-002-23:** *Dado* el entorno `staging`, *cuando* se revisan sus datos, *entonces* son ficticios y no hay datos reales.
- **CA-002-24:** *Dado* un fallo de despliegue, *cuando* ocurre, *entonces* el servicio anterior sigue disponible y se puede volver a la versión previa redesplegándola.
  Manual: redesplegar la versión anterior y confirmar `/up`.

### H-002-5 · Documentos de la plantilla y ADR de R0

- **CA-002-25:** *Dado* el repositorio sin `vendor/`, `node_modules/`, `.git/`, las plantillas `docs/plans/0000-plantilla-plan.md` y `docs/adr/0000-plantilla-adr.md` y los `PROMPT-*.md`, *cuando* se ejecuta `grep -rn "RELLEN[A]R\|CONFIRMA[R]" .`, *entonces* cada línea que devuelve contiene `[PENDIENTE`.
- **CA-002-26:** *Dado* los ADR 0003, 0004, 0005 y 0008, *cuando* se revisan, *entonces* su estado es «Aceptada» con fecha y cada decisión abierta (DA) está cerrada según lo acordado (ADR-0004 DA-1 con SSR diferido y DA-4 con marca blanca fuera de R0; ADR-0008 DA-2 y DA-3 según este plan); el ADR-0007 queda en «Propuesta» y el ADR-0001 lleva su fecha de adopción.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Opciones del instalador de Laravel o de Sail distintas a las documentadas | Verificar la documentación oficial al crear el proyecto y registrar las diferencias en el punto de partida |
| Conflictos de puertos con XAMPP, Windows o `postgres-db-parksys` | Puertos 8080 y 5433 en `.env`; no tocar contenedores de otros proyectos |
| Lentitud de Docker en `C:\` | Aceptada para el plazo (DA-2); reevaluar tras R0 |
| Sin push autorizado (A8) no hay CI ni staging desplegable | Pedir la orden de push el 9-nov; sin ella el calendario del 10-nov se rompe |
| Retraso en la cuenta de Railway (A4) | Lanzar A4 hoy; el esqueleto local con CI ya cumple H1 |
| El hook de formato falla con CRLF | `.gitattributes` impone LF; revisar al clonar |
| El esqueleto en `staging` no carga porque nadie aplicó la migración | CA-002-20 lo declara como precondición manual de Antonio |
| H-002-3 crece más allá de M al absorber la página pública | Se parte con aprobación en vez de avanzar como historia mayor (nota en §1) |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| H-002-1 | `498ebe1` | 2026-11-09 | Esqueleto Laravel 13 sobre la plantilla, servicio `app` con PHP 8.4, pgsql, redis y mailpit; puertos 8080 y 5433; scripts de Composer y npm; Pest y Larastan instalados. `composer check` en rojo hasta H-001-1 (las guardianas citan clases de ese plan) |
| Cuenta de GitHub del repositorio | `17df86e`, `f983d2f` | 2026-11-09 | Documentación de la cuenta de GitHub del repositorio y corrección del helper de credenciales (`docs(env)`) |
| CI backend corregido | `0bc51f5`, `bfffcbe` | 2026-11-09 | `0bc51f5`: instalar dependencias antes de generar la clave de la aplicación. `bfffcbe`: conservar `tests/Unit` con `.gitkeep` (el CI fallaba con «Test directory tests/Unit not found») y registrar el spike S-1 |
| H-002-3 | `7692044` | 2026-11-10 | Migración final de `events`, modelo `Event` traducible, `DemoSeeder` que se niega en producción, ruta pública `/{organization:slug}/events/{event:slug}` (404 para borrador, archivado u otra organización), frontend React + TypeScript con catálogo y guardianas Vitest. Cubre CA-002-13 a 19 (CA-002-16 manual). Verificado en navegador a 375 y 1280 px; `composer check` y npm `typecheck`, `lint`, `test` y `build` en verde, según el commit |
