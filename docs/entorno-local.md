# Entorno local (Docker y VS Code)

> Decisión en ADR-0008. Todo corre en contenedores; los comandos se prefijan con `docker compose exec app`. Los comandos de esta guía existen una vez creado el proyecto con el entorno Docker (`[CONFIRMAR: opciones vigentes de Sail]`).

## 1. Requisitos

| Herramienta | Notas |
|---|---|
| Docker Desktop con el motor **WSL2** | Es lo que usa el equipo. Reservar memoria suficiente para PostgreSQL, Redis y la aplicación |
| VS Code | Con las extensiones de `.vscode/extensions.json`; con la extensión WSL si el repo vive en WSL2 |
| Git | Fin de línea LF (`.gitattributes` lo impone) |

**No hace falta** PHP, Composer ni Node en el equipo: todo lo ejecuta el contenedor. Si hay un PHP instalado (por ejemplo el de XAMPP), no se usa para este proyecto.

## 2. Dónde vive el repositorio

Recomendado: **dentro del sistema de archivos de WSL2** (por ejemplo `~/proyectos/eventos`) y abrirlo con `code .` desde la terminal de WSL. Es mucho más rápido que montar una carpeta de `C:\` en un contenedor. Si el repo se queda en `C:\`, funciona, pero `composer install`, `npm install` y las pruebas serán lentos (ADR-0008, DA-2).

## 3. Crear el proyecto (una sola vez)

El PHP del equipo (8.1, de XAMPP) **no sirve** para crear un Laravel actual. El proyecto se crea desde un contenedor temporal con PHP y Composer actuales, sin instalar nada en el equipo. `[CONFIRMAR: comando vigente en la documentación de Laravel y Sail; las opciones del instalador cambian]`.

Esquema: contenedor temporal con Composer + PHP actual, montando la carpeta del proyecto, para ejecutar `composer create-project laravel/laravel` (o el instalador de Laravel) con el kit de inicio React (Inertia), PostgreSQL, Pest y Sail; después instalar Sail y renombrar el servicio a `app`. Es la Fase 0 de `PROMPT-ARRANQUE.md`: el agente propone el comando exacto y **lo ejecuta una persona**.

## 4. Primer arranque

Una vez creado el proyecto con su `docker-compose.yml`:

```bash
docker compose up -d                              # levanta app, pgsql, redis y mailpit
docker compose exec app composer install
docker compose exec app cp .env.example .env
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed   # base local ficticia
docker compose exec app npm install
docker compose exec app npm run dev                  # Vite con recarga en caliente
```

Variables de `.env` que conviene fijar en **esta máquina** (comprobado el 2026-11-09):

| Variable | Valor | Por qué |
|---|---|---|
| `APP_PORT` | `8080` | El puerto 80 lo ocupa un servicio de Windows (`svchost`) y XAMPP |
| `FORWARD_DB_PORT` | `5433` | El 5432 lo ocupa el contenedor `postgres-db-parksys` de otro proyecto. **No detenerlo ni tocarlo** |
| `APP_SERVICE` | `app` | Solo con Sail: renombra el servicio de la aplicación (ADR-0008) |

El puerto 3306 (MySQL de XAMPP) no choca: este proyecto usa PostgreSQL.

| Servicio | Dirección local |
|---|---|
| Aplicación | http://localhost:8080 |
| **Mailpit** (correos enviados) | http://localhost:8025 |
| Vite | http://localhost:5173 |
| PostgreSQL | `localhost:5433` desde el equipo; `pgsql:5432` entre contenedores (credenciales ficticias de `.env.example`) |

## 5. Comandos de todos los días

```bash
docker compose exec app composer check         # lint + análisis + pruebas + criterios
docker compose exec app composer test
docker compose exec app composer test -- --filter=NombreDeLaPrueba
docker compose exec app composer analyse
docker compose exec app composer format
docker compose exec app php artisan <comando>
docker compose exec app npm run typecheck
docker compose exec app npm run test
docker compose logs -f app                     # registros
docker compose down                            # apaga (conserva los datos)
```

`docker compose down -v` **borra los volúmenes y los datos locales**: pide permiso al agente y se usa a propósito. `docker system prune` y `docker volume rm` también.

## 6. Correo en local

El correo no sale a Internet: `MAIL_*` apunta a **Mailpit**, que guarda cada mensaje y lo muestra en http://localhost:8025. Es la forma de ver cómo quedan las plantillas del módulo de correos con los datos de ejemplo, sin enviar nada real. Probar los envíos reales (entregabilidad, SPF, DKIM) se hace en un entorno con dominio configurado, no aquí.

## 7. VS Code

- Abrir la carpeta raíz del repo (si es WSL2, desde la terminal de WSL con `code .`).
- Instalar las extensiones recomendadas cuando VS Code lo proponga.
- La terminal integrada es donde se ejecutan los comandos `docker compose exec app ...`.
- Claude Code se usa desde la terminal integrada o su extensión; el hook de formato de `.claude/settings.json` ejecuta Pint dentro del contenedor, así que **el contenedor `app` debe estar levantado**. Si no lo está, el hook falla sin bloquear la edición.

## 8. Git y ramas

`staging` es la rama de trabajo; `main`, producción (ADR-0008, `docs/convenciones.md` §5). Verificar la rama antes de empezar: `git branch --show-current`.

### Cuenta de GitHub de este repositorio

El remoto es `SistemasANPR/eventos` y se usa con la cuenta **`Antonio1491`**, que tiene permiso de escritura (comprobado el 2026-11-09). En esta máquina el Administrador de credenciales de Git guarda otra cuenta (`support-parksys`), sin acceso a este repo: empujar con ella da 403. La configuración es **local al repositorio** (no toca la configuración global de Git ni la otra cuenta):

```bash
git remote set-url origin https://Antonio1491@github.com/SistemasANPR/eventos.git
git config --local --add credential.https://github.com.helper ""
git config --local --add credential.https://github.com.helper '!gh auth git-credential'
```

El nombre de usuario va en la URL y el helper de `gh` elige la sesión de `Antonio1491` (debe aparecer en `gh auth status`; si no, `gh auth login`). No cambiar la cuenta activa de `gh` ni borrar credenciales de otras cuentas. Al clonar de nuevo, repetir estos tres comandos. Verificar con `git config --local --list | grep -E "remote.origin.url|credential"`.

## 9. Problemas frecuentes

| Síntoma | Causa y arreglo |
|---|---|
| «Port is already allocated» (80, 3306, 5432 u otro) | Otro servicio o contenedor lo ocupa (en esta máquina: 80, 3306 y el 5432 de `postgres-db-parksys`). Cambiar el puerto en `.env` (`APP_PORT`, `FORWARD_DB_PORT`); no apagar contenedores de otros proyectos |
| `composer install` y las pruebas muy lentos | El repo está en `C:\`. Moverlo al sistema de archivos de WSL2 (§2) |
| Scripts del contenedor que fallan con `\r` | Fin de línea CRLF. Comprobar `.gitattributes` y volver a clonar |
| El hook de formato marca error | El contenedor `app` no está levantado: `docker compose up -d` |
| Cambios que no se reflejan en el navegador | Vite no está corriendo o el puerto 5173 está ocupado |
| La base local quedó inconsistente | `docker compose down -v` y volver a migrar (es destructivo y solo afecta datos ficticios) |
