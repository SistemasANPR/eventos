# [tipo]: [RELLENAR: título corto de la funcionalidad o el cambio]

> Copiar como `AAAA-MM-DD-NNN-tipo-titulo-en-kebab-case.md` (ejemplo: `2026-11-09-003-feat-convocatoria-de-propuestas.md`). `NNN` es el número del plan, consecutivo, y prefija todos sus criterios: `CA-NNN-n`. Es la especificación de SDD (ADR-0001) para esfuerzo M o L. Se presenta y se espera aprobación antes de escribir código. Tipos: los mismos de los commits (`feat`, `fix`, `refactor`...).
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.

- **Fecha:** [RELLENAR: AAAA-MM-DD]
- **Estado:** Borrador
- **Esfuerzo:** S | M | L
- **Deuda relacionada:** [RELLENAR: puntos de `docs/sprints/deuda-tecnica.md`, o «ninguna»]

> Estados válidos: `Borrador`, `Aprobado`, `En curso`, `Terminado`. Escribir uno solo.

## 1. Objetivo y alcance

[RELLENAR: qué problema resuelve y para quién, en tres o cuatro líneas.]

**Dentro:** [RELLENAR]

**Fuera:** [RELLENAR: lo que explícitamente no se hace en este plan]

## 2. Punto de partida

[RELLENAR: estado actual medido, con fecha: archivos, conteos, comportamiento comprobado. Hechos, no suposiciones.]

## 3. Decisiones

Resumen en una línea de las decisiones cerradas; cada una se detalla abajo con la opción recomendada y por qué.

### DA-1 · [RELLENAR: decisión] — se recomienda **[RELLENAR]**

[RELLENAR: opciones, recomendación y motivo. Al cerrarse: «Cerrada el AAAA-MM-DD: [opción]».]

## 4. Contrato

[RELLENAR: lo que otros consumen. Rutas JSON o webhooks (y su entrada en `docs/api/endpoints.md`), Form Requests, tipos de las props, cambios de datos (con la propuesta de `docs/database-design.md` §6). Si no hay contrato nuevo, decirlo.]

## 5. Permisos, aislamiento y datos personales

Responder cada punto; «no aplica» es una respuesta válida si se justifica.

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? (una fila por acción) | [RELLENAR] |
| ¿Qué Policy cubre cada acción y dónde se prueba? | [RELLENAR] |
| ¿Hay modelos nuevos con datos de una organización? ¿Usan `BelongsToOrganization`? | [RELLENAR] |
| ¿El acceso cruza eventos o depende de `event_assignments`? | [RELLENAR] |
| ¿Se guardan datos personales nuevos? ¿Con qué base legal, retención y borrado? (`docs/privacidad.md`) | [RELLENAR] |
| ¿Se suben archivos? ¿Qué tipos, tamaño máximo y disco? | [RELLENAR] |
| ¿Hay dinero, pagos o webhooks? (ADR-0007) | [RELLENAR] |
| ¿Se envían correos o notificaciones? ¿Cuáles y por cola? | [RELLENAR] |
| ¿Qué se registra en la auditoría? | [RELLENAR] |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | [RELLENAR: preparar sin cambiar comportamiento: pruebas, tipos, andamiaje] | S |
| F1 | [RELLENAR] | M |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-NNN-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

- **CA-NNN-1:** *Dado* [estado], *cuando* [acción], *entonces* [resultado observable].
- **CA-NNN-2:** [RELLENAR: caso de error o límite]
- **CA-NNN-3:** [RELLENAR: criterio verificable solo a mano]
  Manual: [RELLENAR: pasos exactos, rutas y anchos de pantalla]

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| [RELLENAR] | [RELLENAR] |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
