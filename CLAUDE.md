# Eventos — contrato de trabajo del agente

> Este archivo es corto a propósito. Las reglas viven en `AGENTS.md` (única fuente de verdad); aquí solo está lo específico de Claude Code. No duplicar contenido de `AGENTS.md`.

@AGENTS.md

## Documentos de contexto

| Archivo | Cuándo leerlo |
|---|---|
| `AGENTS.md` | Siempre (importado arriba) |
| `app/CLAUDE.md` | Al tocar cualquier archivo de `app/` |
| `resources/js/CLAUDE.md` y `design.md` | Al crear o modificar componentes, páginas o estilos |
| `tests/CLAUDE.md` | Al escribir o cambiar pruebas |
| `docs/database-design.md` | Antes de crear, modificar o eliminar datos persistentes |
| `docs/entorno-local.md` | Al arrancar el entorno, ejecutar comandos o resolver problemas de Docker |
| `docs/seguridad.md` | Al tocar autenticación, permisos, archivos, pagos o tenancy |
| `docs/adr/0001-...` | Antes de construir una funcionalidad o módulo nuevo |
| `docs/producto/glosario.md` | Al nombrar modelos, tablas, rutas o textos |
| `docs/sprints/deuda-tecnica.md` | Antes de tocar un área con deuda abierta |

Claude Code carga los `CLAUDE.md` del directorio activo hacia arriba. En conflicto entre documentos: `design.md` prevalece en lo visual, `docs/database-design.md` en datos persistentes y `docs/seguridad.md` en seguridad.

## Lo que impone la herramienta, no el agente

`.claude/settings.json` ya no bloquea ni pide confirmación para ninguna acción (Antonio, 2026-11-12); solo conserva el hook que ejecuta Pint tras cada edición. Si una acción es rechazada por la herramienta, **no buscar otra vía para lograrlo**: informar al usuario y esperar.

Comandos propios (`.claude/commands/`): `/nueva-especificacion`, `/revisar-aislamiento`, `/verificar`.

## Prompt de inicio de sesión

```
Lee el CLAUDE.md raíz y el del directorio en que trabajaremos hoy.
Confirma que entendiste:

1. El módulo: [MÓDULO] y la tarea exacta: [DESCRIPCIÓN]
2. El plan o la especificación vigente: [docs/plans/... o "ninguno"]
3. Los archivos que NO debes modificar en esta sesión
4. El criterio de terminado para esta tarea
5. Si la tarea toca datos de una organización, pagos, permisos o archivos

No escribas código hasta confirmar estos cinco puntos.
Cuando confirmes, muéstrame el plan de implementación en pasos antes de ejecutar.
```

## Verificar el trabajo

Un cambio no está hecho hasta que se ve funcionando: `docker compose exec app composer check`, y en el navegador la ruta afectada a ~375 px y ~1280 px con la consola abierta. Las animaciones de entrada pueden dejar una captura en blanco sin que nada esté roto: esperar o hacer scroll antes de concluir que hay un fallo.

Entorno: desarrollo local con **VS Code y Docker** (Docker Desktop con WSL2); todo se ejecuta en el contenedor `app` (`docker compose exec app ...`), nunca con el PHP, Composer ni Node del equipo. El desarrollo va en la rama **`staging`**; `main` es producción y no se toca (ADR-0008). El correo local lo recibe Mailpit en http://localhost:8025. Puertos locales (`compose.yaml` y `.env.example`): aplicación 8080, Vite 5173, PostgreSQL 5433 (5432 entre contenedores), Redis 6379 y Mailpit 8025 (SMTP 1025). Versiones en el contenedor: PHP 8.4 (runtime de Sail 8.4; 8.4.27) y Node 24 (v24.22.2); PostgreSQL 18. [RELLENAR: qué servicios externos no funcionan en local.]
