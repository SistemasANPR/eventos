# feat: entorno Docker, CI y esqueleto andante

> Épica E-01 de `docs/plan-de-desarrollo.md` (R0). Deja el proyecto Laravel corriendo en Docker, el CI en verde en `staging` y `main`, un recorrido mínimo de punta a punta (organización → evento → página pública) y ese esqueleto desplegado en Railway `staging`.

- **Fecha:** 2026-11-09
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** SSR de Inertia diferido (RC-5, ADR-0004 DA-1) hasta antes de R3; se registrará en `docs/sprints/deuda-tecnica.md` al aprobar el ADR correspondiente.

## 1. Objetivo y alcance

Que cualquiera del equipo levante todo con un comando, que cada cambio pase por un CI que bloquee lo roto, y que exista desde la primera semana un recorrido mínimo funcionando en un entorno real, para que el riesgo de integración aparezca pronto y no el último día.

**Dentro:** creación del proyecto Laravel con el kit de inicio React (Inertia), PostgreSQL, Pest y Sail con el servicio `app` (ADR-0008 DA-1); puertos locales 8080 y 5433; Mailpit; versiones de PHP y Node fijadas; CI en pull requests, `staging` y `main`; protección de ramas; página pública mínima de un evento con Inertia **sin SSR**; despliegue del esqueleto a Railway `staging`.

**Fuera:** producción (plan 010); evento completo (plan 003); SSR (R3); cualquier módulo de negocio; datos reales.

### Historias

| Historia | Enunciado | Tam. | Dependencias |
|---|---|---|---|
| H-002-1 | Como desarrollador, quiero levantar todo con un comando, para trabajar con paridad con producción | M | — |
| H-002-2 | Como líder, quiero CI en verde en `staging` y `main` con ramas protegidas, para no integrar código roto | S | H-002-1, A8 |
| H-002-3 | Como visitante, quiero ver la página pública de un evento, para validar el recorrido completo | M | H-002-1, H-001-1 |
| H-002-4 | Como líder, quiero el esqueleto desplegado en Railway `staging`, para probar en un entorno real | S | H-002-2, A4 |

## 2. Punto de partida

Medido el 2026-11-09: el repositorio tiene la plantilla en la raíz y la rama de trabajo es `staging` (commit `0098bc0`); no hay `composer.json`, `package.json`, `artisan`, `Dockerfile` ni `docker-compose.yml`. El PHP del equipo (8.1, de XAMPP) no sirve para un Laravel actual, así que el proyecto se crea desde un contenedor temporal. En esta máquina el puerto 80 lo ocupa un servicio de Windows y el 5432 el contenedor `postgres-db-parksys` de otro proyecto, que no se toca (`docs/entorno-local.md`). El remoto de GitHub está vacío: no hay CI ni ramas protegidas hasta el primer push (acción A8, que ordena Antonio).

## 3. Decisiones

### DA-1 · Base del entorno Docker — se recomienda **Laravel Sail con el servicio renombrado a `app`**

Cierra ADR-0008 DA-1. Alternativa: un `docker-compose.yml` propio. Las opciones vigentes del instalador y de Sail se verifican en la documentación oficial al crear el proyecto `[PENDIENTE: comando y opciones vigentes]`.

### DA-2 · Ubicación del repositorio — se recomienda **quedarse en `C:\`**

Cierra ADR-0008 DA-2 para el plazo del 18-nov: se acepta la lentitud de `composer install`, `npm install` y las pruebas. Se reevalúa tras R0.

### DA-3 · `staging` desplegado — se recomienda **desplegarlo en Railway el día 3**

Cierra ADR-0008 DA-3: la ANPR debe ver la convocatoria y aprobar el aviso de privacidad antes del lanzamiento.

### DA-4 · SSR — se recomienda **Inertia sin SSR en R0**

Cierra ADR-0004 DA-1 y DA-4 para R0: evita un proceso Node en producción durante la semana crítica. La página lleva metadatos básicos; SSR antes de R3.

### DA-5 · Protección de ramas — se recomienda **proteger `main` (pull request, CI y una revisión) y exigir CI en `staging`**

Cierra ADR-0008 DA-4; depende del plan de GitHub de la organización `[PENDIENTE: confirmar que el plan lo permite]`.

## 4. Contrato

- **Entorno:** `docker-compose.yml` con servicios `app`, `pgsql`, `redis` y `mailpit`; Vite en 5173; aplicación en `http://localhost:8080`; PostgreSQL en `localhost:5433`. Cada archivo `.env*` se trata como secreto; `.env.example` solo con valores ficticios.
- **Comandos** (deben existir en `composer.json` y `package.json`): `composer check`, `test`, `analyse`, `lint`, `format`, `test:coverage`, `check:criteria`; `npm run typecheck`, `lint`, `test`, `build`.
- **CI** (`.github/workflows/ci.yml`): se ejecuta en pull requests y en pushes a `staging` y `main`, con PostgreSQL de servicio, Pint, Larastan, Pest, `check:criteria`, `composer audit`, `npm audit`, búsqueda de secretos y el *build* del frontend.
- **Ruta web pública:** `GET /{organization:slug}/events/{event:slug}` (Inertia, sin SSR), con binding acotado a la organización; un evento no publicado responde 404. La ruta definitiva y su resolución por configuración quedan alineadas con el plan 001.
- **Sin endpoints JSON ni webhooks nuevos**; solo `/up` de salud.
- **Propuesta de datos (`docs/database-design.md` §6):** este plan no crea tablas de negocio propias. Reutiliza `organizations` (plan 001) y un `events` mínimo cuyo modelo definitivo es el del plan 003; para el esqueleto se usa la migración del plan 003 en su versión mínima (nombre, `slug`, estado, `organization_id`). *Alternativa descartada:* un modelo de evento temporal que luego se reemplace, por costo de migración doble.

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | La página pública es de lectura anónima de eventos publicados. El resto (CI, despliegue) es de Antonio como dueño de ramas y secretos |
| ¿Qué Policy cubre cada acción y dónde se prueba? | No hay acciones autenticadas nuevas. La visibilidad de un evento publicado se prueba en una prueba de funcionalidad de la ruta pública |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | El `Event` mínimo usa `BelongsToOrganization` (plan 001) y está cubierto por las guardianas |
| ¿El acceso cruza eventos o depende de `event_assignments`? | No |
| ¿Se guardan datos personales nuevos? | No. Los datos locales y de `staging` son ficticios (`docs/privacidad.md`) |
| ¿Se suben archivos? | No aplica |
| ¿Hay dinero, pagos o webhooks? | No aplica |
| ¿Se envían correos o notificaciones? | No. Mailpit local queda listo para los planes siguientes |
| ¿Qué se registra en la auditoría? | Nada nuevo; los despliegues los registra el proveedor y el CI |

## 6. Estrategia por fases

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | Crear el proyecto Laravel en contenedor temporal (lo ejecuta una persona), instalar Sail con servicio `app`, fijar puertos y versiones, copiar la plantilla encima sin sobrescribir `composer.json`, `package.json` ni `.gitignore` (H-002-1) | M |
| F1 | Scripts de Composer y npm que las reglas dan por existentes; CI en verde y protección de ramas tras el primer push (H-002-2) | S |
| F2 | Página pública mínima con Inertia sin SSR, claves i18n y recorrido organización → evento → página (H-002-3) | M |
| F3 | Proyecto de Railway `staging` con web, worker, PostgreSQL y Redis; despliegue desde `staging` (H-002-4) | S |

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

### H-002-2 · CI y ramas protegidas

- **CA-002-6:** *Dado* un push a `staging` o `main`, o un pull request, *cuando* corre el CI, *entonces* ejecuta lint, análisis estático, pruebas, `check:criteria`, `composer audit`, `npm audit`, búsqueda de secretos y *build*, y falla si cualquiera falla.
- **CA-002-7:** *Dado* un cambio que rompe una prueba, *cuando* se abre el pull request, *entonces* el CI queda en rojo y bloquea la integración.
- **CA-002-8:** *Dado* un secreto de prueba incluido en un archivo versionado, *cuando* corre el CI, *entonces* la búsqueda de secretos falla.
- **CA-002-9:** *Dado* la protección de ramas, *cuando* alguien intenta integrar a `main` sin pull request, sin CI en verde o sin una revisión, *entonces* GitHub lo rechaza; en `staging` exige CI en verde.
  Manual: intentar un push directo a `main` con una cuenta sin permisos de administrador y comprobar el rechazo.
- **CA-002-10:** *Dado* el CI, *cuando* se revisan sus variables, *entonces* no contiene credenciales en el repositorio y los secretos viven en los secretos de GitHub.

### H-002-3 · Página pública mínima (esqueleto andante)

- **CA-002-11:** *Dado* una organización con un evento publicado, *cuando* un visitante abre la ruta pública del evento, *entonces* ve el nombre del evento en su idioma y responde 200 sin sesión.
- **CA-002-12:** *Dado* un evento en borrador o de otra organización, *cuando* se abre su ruta pública, *entonces* responde 404.
- **CA-002-13:** *Dado* la página pública, *cuando* se inspecciona la respuesta, *entonces* no expone identificadores internos ni datos de otras organizaciones y los textos visibles salen de las claves de i18n (es y en).
- **CA-002-14:** *Dado* la página pública, *cuando* se visualiza a ~375 px y ~1280 px, *entonces* es legible y no tiene errores en la consola.
  Manual: abrir la ruta a 375 px y 1280 px con la consola abierta y comprobar estados de carga, vacío y error.
- **CA-002-15:** *Dado* el *build* del frontend, *cuando* se ejecutan `npm run typecheck`, `lint`, `test` y `build`, *entonces* pasan sin `any` ni supresiones.

### H-002-4 · Esqueleto desplegado en Railway `staging`

- **CA-002-16:** *Dado* un push a `staging` con el CI en verde, *cuando* se despliega, *entonces* `/up` responde 200 en el entorno `staging` de Railway y la página pública del evento ficticio carga.
  Manual: abrir la URL de `staging`, comprobar `/up` y la página del evento.
- **CA-002-17:** *Dado* el entorno `staging`, *cuando* se revisan sus variables, *entonces* están definidas en el proveedor (sin valores en el repositorio) y `APP_DEBUG` está apagado.
- **CA-002-18:** *Dado* el entorno `staging`, *cuando* se revisan sus datos, *entonces* son ficticios y no hay datos reales.
- **CA-002-19:** *Dado* un fallo de despliegue, *cuando* ocurre, *entonces* el servicio anterior sigue disponible y se puede volver a la versión previa redesplegándola.
  Manual: redesplegar la versión anterior y confirmar `/up`.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Opciones del instalador de Laravel o de Sail distintas a las documentadas | Verificar la documentación oficial al crear el proyecto y registrar las diferencias en el punto de partida |
| Conflictos de puertos con XAMPP, Windows o `postgres-db-parksys` | Puertos 8080 y 5433 en `.env`; no tocar contenedores de otros proyectos |
| Lentitud de Docker en `C:\` | Aceptada para el plazo (DA-2); reevaluar tras R0 |
| Sin push autorizado (A8) no hay CI ni staging desplegable | Pedir la orden de push el 9-nov; sin ella el calendario del 10-nov se rompe |
| Retraso en la cuenta de Railway (A4) | Lanzar A4 hoy; el esqueleto local con CI ya cumple H1 |
| El hook de formato falla con CRLF | `.gitattributes` impone LF; revisar al clonar |

## 9. Registro de avance

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
