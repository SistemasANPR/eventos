# R1: plan de ejecución con agentes

Preparado el 2026-11-23. No es una especificación (esas viven en `docs/plans/`): es el orden de trabajo, el reparto entre agentes y las reglas para que no se pisen. Meta de R1 (`docs/plan-de-desarrollo.md` §5): planes 011 y 012 terminados el **18-dic-2026**, antes del cierre de la convocatoria del 21-ene-2027; 2FA (H-014-1) antes de invitar evaluadores externos.

## 1. Estado de partida (2026-11-23)

| Plan | Estado | Qué falta |
|---|---|---|
| 011 evaluación por comité | En curso, **aprobado** (ampliación del 2026-11-23) | F3a, F3b, F4, F5, F6 |
| 012 decisiones y resultados | Borrador | Aprobación; F0 a F3 |
| 014 endurecimiento y privacidad | Borrador | Aprobación; F1 (2FA) bloquea invitar evaluadores |
| 013 correos avanzados | Borrador | Fuera del camino crítico: puede terminar después del cierre |
| 010 producción y operación | Borrador, sin avance registrado | Respaldos y restauración probada (insumo de 014 F3); trabajo humano en gran parte |

Antes de empezar, medir (no suponer): `git status --short` limpio en `staging`, y la suite en la base de pruebas propia de cada agente. En sesiones anteriores había fallos ajenos conocidos (exportación CA-009-11 a 19 si falta `maatwebsite/excel` en el `vendor/` del entorno; un Arch por `assert()`); el agente debe distinguirlos de los suyos y no arreglarlos dentro de su rama.

## 2. Compuertas de aprobación (las pone una persona)

Ningún agente escribe código de un plan en Borrador (ADR-0001). Hace falta, registrada en el campo `Estado` y en el commit:

1. **Plan 012**: aprobar la especificación, DA-1 a DA-4 y la propuesta de datos de §4. Mínimo para arrancar: F0 y F1 (H-012-1, H-012-5). F2 y F3 pueden aprobarse después.
2. **Plan 014**: aprobar la especificación y la propuesta de datos de 2FA (F0 y F1). RLS, anonimización y retención pueden esperar.
3. **Migraciones**: el agente las genera y las aplica solo en la base de pruebas propia; **una persona** las aplica a la base local de desarrollo y a `staging` (AGENTS.md, acción 1).

## 3. Carriles

El camino crítico es 012 F0-F1 → 011 F3a → 011 F3b y F4 → 011 F5 → 011 F6 → 012 F2-F3. El carril de 2FA corre en paralelo.

| Carril | Trabajo | Depende de | Rama |
|---|---|---|---|
| **A** (crítico) | 012 F0 y F1: tabla de transiciones, Actions por transición, historial y auditoría (incluye `MarkSubmissionUnderReview`) | Aprobar el plan 012 | `feat/plan-012-transiciones` |
| **B** | 014 F1: 2FA obligatoria, códigos de recuperación y reinicio | Aprobar el plan 014 | `feat/plan-014-2fa` |
| **C** | 011 F3a: migraciones de `review_assignments` (con `cancelled`, `assigned_by`, `assigned_at`, `source`) y de `review_assignment_scopes`; asignación individual y en lote; informe de omitidas; correo resumen | Carril A mergeado (`MarkSubmissionUnderReview`). La parte de datos y las Policies pueden adelantarse con el contrato de la Action simulado | `feat/plan-011-asignacion` |
| **D** | 011 F3b: alcances por eje y convocatoria, regla permanente y job idempotente; vista por evaluador; invitar desde la pantalla (H-011-8) | C mergeado; B mergeado para invitar a evaluadores externos | `feat/plan-011-alcances` |
| **E** | 011 F4: panel `Reviewer`, tablero y `SubmitReview` | C mergeado (tablas); B mergeado (2FA) | `feat/plan-011-panel-evaluador` |
| **F** | 011 F5 (conflicto y modo ciego) y F6 (resultados, avance por evaluador y cierre) | E mergeado | `feat/plan-011-conflicto-ciego`, `feat/plan-011-resultados` |
| **G** | 012 F2 y F3: decisión masiva, publicar resultados, correos de resultado y recordatorio | A mergeado; F6 de 011 para decidir con resultados | `feat/plan-012-decision-masiva` |

**Oleadas** (qué corre a la vez):

1. A y B en paralelo (no comparten archivos más que traducciones y el catálogo de permisos).
2. C, y D cuando C esté en `staging`. Mientras tanto E puede avanzar solo hasta donde no necesite las tablas.
3. E y, en paralelo, la parte de F5 que no toque el panel.
4. F6 y G.

Se fusiona a `staging` con `--no-ff` solo tras `composer check` y la revisión del diff; después se borra la rama (AGENTS.md, §Forma de trabajar).

## 4. Reglas para que los agentes no se pisen

- **Un agente, una rama, un árbol de trabajo y una base de pruebas propia.** Se usan *worktrees* (`git worktree`) y bases `testing_<carril>` distintas: compartir la base de pruebas causó interbloqueos y fallos falsos en los planes 009 y 021.
- **Archivos compartidos de alto riesgo**, con un solo dueño por oleada (el resto los toca solo para añadir líneas y resuelve el conflicto al integrar): `app/Support/**/RolePermissions*` y sus migraciones de sincronización, `NotificationCatalog` y sus plantillas es/en, `lang/{es,en,pt}`, el proveedor del panel, `docs/database-design.md`. Las migraciones se ordenan por marca de tiempo: un agente que adelanta una migración la nombra con su fecha real, sin reutilizar prefijos.
- **Commits**: uno por cambio coherente y verificado, con descripción en español sin tildes y la línea `Co-Authored-By`. Nunca push, nunca commit ni merge en `main` (AGENTS.md acción 12).
- **Prueba primero** en permisos, aislamiento, dinero y bugs; cada criterio `CA-NNN-n` cita su prueba (`composer check:criteria`).
- **Verificación**: `docker compose exec app composer check`, y en el navegador la ruta afectada a ~375 y ~1280 px con la consola abierta. Si no hay navegador en la sesión, el agente lo declara como «no ejecutado», no lo da por hecho. Las pruebas corren con `APP_ENV=testing`.
- **Registro**: al cerrar cada fase, un commit `docs(plans)` con el hash, la fecha y la salida real de las validaciones, incluidas las que no se ejecutaron.
- **Deuda**: la que quede se anota en `docs/sprints/deuda-tecnica.md`; no se arregla de paso.

## 5. Plantilla del encargo a cada agente

```
Plan: docs/plans/<archivo>. Fase: <Fn>. Rama: <rama>, creada desde staging actualizada.
Lee: AGENTS.md, app/CLAUDE.md, tests/CLAUDE.md, docs/seguridad.md, docs/database-design.md §6
(y resources/js/CLAUDE.md y design.md si tocas interfaz React).
Alcance: solo los criterios <CA-...>. Fuera de alcance: <lista>.
No modifiques: <archivos compartidos de otros carriles>.
Entorno: worktree propio, base testing_<carril>, APP_ENV=testing.
Haz: pruebas primero (en rojo), código mínimo, composer check, commit por cambio coherente.
No hagas: push, merge a staging, aplicar migraciones fuera de tu base de pruebas.
Entrega: hash de cada commit, salida real de las validaciones, lo no ejecutado y las desviaciones del plan.
```

Un integrador (persona o sesión distinta de los agentes) revisa el diff completo, vuelve a correr `composer check` en su propio árbol y fusiona. Los agentes no verifican su propio trabajo.

## 6. Riesgos

| Riesgo | Mitigación |
|---|---|
| 012 F1 retrasa todo el carril crítico | Arrancarlo primero y con el menor alcance; el contrato de `MarkSubmissionUnderReview` se fija en F0 para que C pueda adelantar datos y Policies |
| 2FA mal hecha bloquea a todos los usuarios | Probarla en `staging` con una cuenta de prueba antes de activarla; códigos de recuperación y reinicio por `org_admin` o plataforma desde el primer día (CA-014-4 y 5) |
| Conflictos en catálogo de permisos y correos | Dueño único por oleada (§4) |
| Fechas: receso de fin de año | Si no cabe, se recorta el modo ciego (H-011-4) antes que cualquier Policy, como dice el plan 011 §1 |
| Volumen de propuestas 2027 desconocido | Medirlo al cierre; decide si hace falta asignación automática balanceada (R2) |

## 7. Avance (2026-11-24)

Todo está fusionado en `staging` (sin push) y verificado sobre el resultado integrado con la suite completa: 2624 pruebas en verde y 11 fallos ajenos conocidos (9 de exportación CA-009-11 a 19 por falta de `maatwebsite/excel` en el `vendor/` del entorno, y 2 de `PrivacyNoticePanelTest` CA-003-11 y CA-003-14, que fallan igual sin estos cambios). Ninguna fase se verificó en navegador a ~375 y ~1280 px.

| Oleada | Carril | Trabajo | Fusión en `staging` |
|---|---|---|---|
| 1 | A | Plan 012 F0 y F1: transiciones, decisiones y reversa, `MarkSubmissionUnderReview` | `5448396` |
| 1 | B | Plan 014 F1: 2FA obligatoria | `616b47e` |
| 2 | C | Plan 011 F3a: asignación individual y en lote | `eb0eb0b` |
| 3 | D | Plan 011 F3b: reglas de alcance, vista por evaluador, invitar evaluadores | `bbde3da` |
| 3 | E | Plan 011 F4: panel `Reviewer`, tablero y `SubmitReview` | `4fda7bb` |
| 4 | G | Plan 011 F4b: pantalla de rúbricas (hueco detectado: el contrato listaba `RubricResource` y ninguna fase lo construía) | `f203c68` |
| 4 | H | Plan 011 F5: conflicto de interés y revisión ciega (cierra DT-29) | `9dd8bd9` |
| 4 | I | Plan 011 F6: resultados, avance por evaluador, cierre del periodo | `f431e1b` |

**Plan 011 (evaluación por comité): construido de F0 a F6.** Lo que falta no es código sino verificación y decisiones.

### Pendiente de R1
- **Plan 012 F2 y F3** (decisión masiva, publicar resultados, correos de resultado y recordatorio): requiere aprobar esas fases (hoy solo F0 y F1). Debe consumir `ComputeSubmissionResults` del plan 011 y excluir las asignaciones con conflicto declarado (DT-37).
- **Plan 014 F2 a F5** (RLS, restauración y runbooks, exportar y anonimizar, retención): en Borrador salvo F0 y F1.
- **Plan 013** (correos avanzados) y **plan 010** (producción y respaldos, sin avance registrado): fuera del camino crítico, pero 014 F3 depende de los respaldos del 010.
- **Acciones humanas**, que ningún agente hace: aplicar las migraciones a la base local y a `staging` (2FA fuerza a todos los usuarios a configurarla: dejar antes al menos 2 `org_admin` por organización y 2 usuarios de plataforma); verificar en navegador cada pantalla nueva; activar `TWO_FACTOR_REQUIRED` en `staging` con una cuenta de prueba antes de invitar evaluadores externos.
- **Decisiones abiertas**: permisos de resultados y cierre (hoy `org_admin` y `organizer`; el plan 011 §5 los reserva al administrador y al coordinador de evento, DT-41); dirección de la escala de la rúbrica (DT-38); metadatos de PDF y PPTX en modo ciego (DT-34).

### Aprendizajes del proceso
- Un worktree y una base de pruebas por agente evitó los interbloqueos de la base compartida. Hay que ejecutar la suite en un worktree limpio: en el árbol principal `storage/framework/testing/disks/local` pertenece a otro usuario y las pruebas con `Storage::fake` fallan.
- `git stash` es global entre worktrees: dos agentes aplicaron por error el stash de otro. Está prohibido en los encargos.
- Los conflictos al integrar fueron siempre en documentos (Estado del plan, §9, tabla de deuda) y en el orden numérico del menú del evento; la propiedad de archivos por carril los redujo, no los eliminó.
- El integrador verifica el candidato integrado en un worktree desechable y repite la fusión real sobre `staging` comprobando que el árbol resultante es idéntico al verificado.
