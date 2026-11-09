# ADR-0008: Entorno local con Docker y modelo de ramas (`main` y `staging`)

- **Fecha:** 2026-11-09
- **Estado:** Aceptada (con decisiones abiertas de implementación)
- **Alcance:** entorno de desarrollo, Git y CI

## Contexto

El equipo desarrolla en local con VS Code y Docker. El PHP instalado en las máquinas (por ejemplo el de XAMPP) no corresponde a la versión que exige un Laravel actual, y las diferencias entre máquinas son una fuente clásica de errores. El producto usa PostgreSQL, Redis, colas y correo, que conviene tener idénticos a producción. Además, el equipo decidió que **todo el desarrollo va en la rama `staging`**, que es la rama de pruebas, y que `main` queda para lo que ya se probó.

## Decisión

1. **Todo corre en contenedores.** La aplicación, las pruebas, el análisis estático, Composer y Node se ejecutan dentro del contenedor de la aplicación; no se usa el PHP, Composer ni Node del equipo para ejecutar el proyecto. Los comandos documentados se prefijan con `docker compose exec app`.
2. **Servicios locales:** `app` (PHP y herramientas), `pgsql` (PostgreSQL, el mismo motor que producción), `redis`, **`mailpit`** (bandeja de correo local, para ver los correos y probar el módulo de plantillas sin enviar nada real) y Vite para el frontend.
3. **Datos locales ficticios.** Nunca se copian datos de producción al entorno local (`docs/privacidad.md`).
4. **VS Code** es el editor estándar, con extensiones recomendadas en `.vscode/extensions.json`.
5. **Ramas:**
   - **`staging`** es la rama de integración y pruebas: aquí va todo el desarrollo. Para un trabajo grande se puede abrir una rama corta desde `staging`, que vuelve a `staging` por pull request.
   - **`main`** es producción: solo recibe lo que pasó el punto de control de `staging`, mediante pull request con aprobación humana.
   - El agente de código trabaja en `staging`. **Nunca hace commit ni merge en `main`** (`AGENTS.md`, acción 12). Los commits se hacen solo si el usuario los pide; `git push`, nunca sin petición explícita.
6. **CI** corre en cada pull request y en cada push a `main` y a `staging`.
7. **Fin de línea LF** en todo el repo (`.gitattributes`), para que los scripts funcionen igual dentro de los contenedores y en Windows.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Ejecutar con el PHP y Composer del equipo (XAMPP) | Versiones distintas por máquina y sin paridad con producción |
| Una sola rama `main` con ramas por funcionalidad | El equipo decidió trabajar sobre `staging`; se reconsidera si el equipo crece |
| Dev Containers de VS Code | Válido y compatible con esta decisión; puede adoptarse más adelante sin cambiar los comandos |
| Una base de datos distinta en local (SQLite, MySQL) | Rompe la paridad con PostgreSQL y oculta errores de RLS y de restricciones |

## Consecuencias

**Positivas:** entornos reproducibles, el mismo motor de base de datos que producción, arranque rápido de personas nuevas y un agente que ejecuta las mismas herramientas que el equipo.

**Negativas o riesgos:** mayor consumo de memoria y disco; en Windows el acceso a archivos desde contenedores es lento si el repo está en una unidad de Windows (ver DA-2); conflictos de puertos con otros servicios locales como XAMPP (80, 443 y 3306).

**Qué cambia para el equipo:** leer `docs/entorno-local.md`; usar el prefijo `docker compose exec app` en los comandos; trabajar en `staging`.

## Decisiones abiertas

- **DA-1: base del entorno Docker.** Se recomienda **Laravel Sail** (oficial, incluye PostgreSQL, Redis y Mailpit) con el servicio de la aplicación **renombrado a `app`** (`APP_SERVICE=app`), para que el prefijo documentado sea el mismo con o sin Sail. Alternativa: un `docker-compose.yml` propio. `[CONFIRMAR: opciones vigentes de Sail al crear el proyecto.]`
- **DA-2: ubicación del repo en Windows.** Se recomienda clonarlo **dentro del sistema de archivos de WSL2** y abrirlo con VS Code (extensión WSL): es notablemente más rápido que montar una carpeta de `C:\` en el contenedor. Si se queda en `C:\`, se acepta la lentitud, sobre todo en `composer install`, `npm install` y las pruebas.
- **DA-3: entorno desplegado de `staging`.** ¿`staging` es solo una rama, o también un servidor donde el equipo y la ANPR prueban? Se recomienda **empezar solo con la rama** y desplegarla cuando haga falta una revisión compartida (antes del lanzamiento de la convocatoria, por ejemplo). `docs/operacion.md` describe ambos.
- **DA-4: protección de ramas en GitHub.** Se recomienda proteger `main` (pull request, CI en verde y una revisión) y exigir CI en verde en `staging`. Depende del plan de GitHub de la organización.

## Referencias

- `docs/entorno-local.md`, `docs/operacion.md` §1, `docs/convenciones.md` §5, `.github/workflows/ci.yml`
