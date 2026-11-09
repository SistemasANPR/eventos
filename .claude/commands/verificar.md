---
description: Ejecuta la definición de terminado y reporta la salida real
---

Comprueba si el trabajo actual cumple la definición de terminado de `AGENTS.md` (§Documentación y terminado). No edites código: solo ejecuta y reporta.

1. `git status --short` y `git diff --check`.
2. `composer check` (lint, análisis estático, pruebas y criterios).
3. Si hubo cambios en `resources/js/`: `npm run typecheck`, `npm run lint`, `npm run test` y `npm run build`.
4. Si se añadió un modelo con datos de organización: confirma que `tests/Feature/Tenancy/` lo cubre.
5. Revisa el diff completo: sin secretos, logs, artefactos ni cambios ajenos.
6. Si hay un plan en curso: confirma que está actualizado y que cada `CA-NNN-n` tiene prueba.

Reporta cada comando con su resultado **real**. Si un comando no existe o falló por configuración, dilo tal cual; no declares que pasó. Termina con una lista corta: qué pasa, qué falla, qué no se pudo ejecutar.
