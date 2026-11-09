# Prompt de arranque para un proyecto nuevo

Úsalo con una carpeta de proyecto **vacía** que contiene solo esta plantilla copiada (o con el proyecto Laravel recién creado con `laravel new` y la plantilla encima). Pégalo en Claude Code. Son tres fases; no pasar a la siguiente sin aprobación explícita.

---

```
Vamos a arrancar un proyecto nuevo con la plantilla Laravel que ya está en este
directorio. Trabaja en tres fases y NO pases a la siguiente sin mi aprobación.

REGLAS DURANTE TODO EL PROCESO
- La plantilla es la forma; yo soy la fuente de verdad del contenido. Nunca
  inventes nombres, versiones ni decisiones de producto: si falta un dato,
  déjalo como `[PENDIENTE: pregunta concreta]` y listalo al final de la fase.
- No instales dependencias ni ejecutes migraciones: me dices el comando y lo
  ejecuto yo.
- Entorno: desarrollo local con Docker y VS Code, rama `staging` (ADR-0008).
  Todo comando va con el prefijo `docker compose exec app`; no uses el PHP,
  Composer ni Node del equipo. Confirma con `git branch --show-current` que
  estás en `staging` y no trabajes en `main`.
- No crees commits. Nunca hagas push.

FASE 1 — LECTURA Y PREGUNTAS (sin editar nada)
1. Lee AGENTS.md, CLAUDE.md, app/CLAUDE.md, resources/js/CLAUDE.md,
   tests/CLAUDE.md, design.md y todos los ADR de docs/adr/.
2. Lee docs/producto/ para entender el producto de origen.
3. Entrégame: (a) un resumen de 10 líneas de lo que entendiste del producto y
   de las reglas que más pesan; (b) la lista de marcadores [RELLENAR] y
   [CONFIRMAR] que no puedes resolver sin mí, agrupados por documento;
   (c) las decisiones abiertas de los ADR 0003, 0004, 0005 y 0007 con tu
   recomendación para cada una.
Detente aquí y espera mi aprobación.

FASE 2 — ADAPTACIÓN DE DOCUMENTOS
1. Resuelve los marcadores con mis respuestas. Donde haya versiones, consulta
   la documentación oficial vigente y cítala; no uses memoria.
2. Cierra las decisiones abiertas de los ADR según lo que acordamos y cambia su
   estado a Aceptada con la fecha.
3. Ajusta docs/producto/glosario.md al vocabulario definitivo.
4. Rellena el CLAUDE.md raíz (nombre, entorno) y AGENTS.md (producto, invariante
   propia, acción sensible propia).
5. Verifica: `grep -rn "RELLENAR\|CONFIRMAR" .` solo puede devolver
   [PENDIENTE]; todo comando de AGENTS.md debe existir en composer.json o
   package.json; ejecuta `git diff --check`.
6. Muéstrame `git status --short` y los [PENDIENTE] que quedaron.
Detente aquí y espera mi aprobación.

FASE 3 — PLAN DEL NÚCLEO
1. Revisa docs/plans/2026-11-09-001-feat-nucleo-multitenant.md y proponme los ajustes a sus
   criterios de aceptación y a sus decisiones DA según lo acordado.
2. Espera mi "adelante". Después implementa por fases, una a la vez: prueba
   primero, cambio mínimo, `composer check` con salida real, y me muestras el
   diff antes de cualquier commit.
Nunca encadenes dos fases del plan sin mi aprobación.

Empieza por la fase 1.
```
