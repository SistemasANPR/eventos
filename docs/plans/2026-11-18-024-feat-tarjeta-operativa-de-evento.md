# feat: tarjeta operativa de evento (lo que requiere atención, no solo qué es)

> Plan 024. Es la especificación SDD (ADR-0001). Se presenta y se espera aprobación antes de escribir código. `NNN` = 024: todos sus criterios son `CA-024-n`.
> `composer check:criteria` lee el **Estado** y los criterios de este archivo: no cambiar el formato de esas líneas.
> El número 023 queda reservado para el plan de formato de hora del evento, hoy numerado 022 por error (choca con el plan de la landing de la convocatoria).

- **Fecha:** 2026-11-18
- **Estado:** Borrador
- **Esfuerzo:** M
- **Deuda relacionada:** ninguna

## 1. Objetivo y alcance

La lista de eventos es también el **selector de evento** (plan 003, DA-8): el organizador llega para decidir con cuál trabajar. Hoy la tarjeta dice qué es el evento (nombre, fechas, ciudad, estado), pero no qué necesita. Este plan la convierte en una **tarjeta operativa**: añade el estado de la convocatoria, cuántas propuestas hay y cuántas requieren atención, y pone la acción principal a la vista.

**Dentro:** estado de la convocatoria del evento en la tarjeta; conteo de propuestas y de las que requieren atención; acción principal visible según el estado del evento (borrador: **Publicar**; publicado: **Abrir**); tratamiento visual propio de borrador y archivado; agrupar o filtrar la lista cuando hay muchos eventos.

**Fuera:** una vista de lista compacta con interruptor (opción C del análisis de UX; se evalúa si una organización pasa de unos 15 eventos); portada o logotipo por evento; cambiar de dónde sale el selector de evento; métricas de ingresos o inscripciones (R3).

## 2. Punto de partida

Medido el 2026-11-18. La **versión A** de la tarjeta ya está hecha (`EventsTable`):

```
┌──────────────────────────────┐
│ Congreso de demostración   ⋮ │  nombre primero; menú de opciones arriba a la derecha
│ 📅 10–12 mar. 2027 · En 112 días │  rango corto en la zona del evento y cuánto falta
│ 📍 Ciudad de México           │
│ ✓ Publicado                  │  estado al pie, con icono y texto
└──────────────────────────────┘
```

- La tarjeta entera abre el evento; el menú ya no repite «Ver» (plan 015, CA-015-4 revisado el 2026-11-18).
- Quitó el tipo del evento de la tarjeta y el filete de color superior.
- Mejora la lectura, pero **no responde a qué hay que atender**: la tarjeta no consulta convocatorias ni propuestas.
- La lista pagina de 10 en 10 y ordena por fecha de inicio descendente. No agrupa ni filtra.
- Propuestas y convocatorias existen (planes 004, 006 y 009); la evaluación y las decisiones (planes 011 y 012) aún no, así que el estado de una propuesta que «requiere atención» hoy es solo `submitted`.

## 3. Decisiones

### DA-1 · Qué significa «requiere atención» — se recomienda **propuestas recibidas sin decisión**

Opciones: (a) propuestas en `submitted`; (b) propuestas sin evaluador asignado; (c) propuestas sin decisión (`submitted` o `under_review`). Hoy solo existe (a), porque los planes 011 y 012 están en curso. Recomendación: definirlo como **«sin decisión»** (c), que coincide con (a) hasta que exista la evaluación y sigue siendo correcto después. Se calcula en una sola consulta con `withCount`.

### DA-2 · Qué se muestra de la convocatoria — se recomienda **su estado y cuánto falta para cerrar**

Opciones: (a) solo el estado (`Abierta`, `Cerrada`, `Programada`); (b) estado y fecha de cierre; (c) nada. Un evento puede tener varias convocatorias (una por tipo de propuesta). Recomendación: (b), resumida a la **más relevante** (la abierta con cierre más próximo; si no hay, la programada; si no, la cerrada). El texto es corto: «Abierta · cierra en 12 días».

### DA-3 · Acción principal por estado — se recomienda **Publicar en borrador y Abrir en publicado**

Un borrador necesita ser publicado; un publicado se abre. Un archivado no lleva acción visible. La acción respeta los permisos de hoy (CA-015-7): quien no puede publicar no ve **Publicar** y ve **Abrir**. La tarjeta sigue siendo clicable; el botón solo hace visible lo que ya hace el clic.

### DA-4 · Estados apagados y punteados — se recomienda **sí, sin depender del color**

Borrador: borde punteado y texto «Borrador». Archivado: tarjeta atenuada (opacidad) y sin franja operativa. No se vuelve a usar un filete de color (decisión del 2026-11-18). Cada estado conserva icono y texto (`design.md` §3.1).

### DA-5 · Muchos eventos — se recomienda **filtro por estado y agrupar en Próximos, Pasados y Archivados desde 8 eventos**

Con pocos eventos la lista queda plana. Con más, se agrupa. El umbral (8) es una recomendación a validar con el uso real; si una organización llega a unos 15, se reevalúa la opción C (vista de lista).

### DA-6 · Posición del menú — **cerrada el 2026-11-18: arriba a la derecha**

Es la convención y deja el nombre como primer elemento de lectura.

## 4. Contrato

**Datos:** no hay migración ni columna nueva. Se leen datos existentes. La consulta de la lista añade el conteo de propuestas (`withCount`) y carga una sola vez la convocatoria más relevante, para **no hacer una consulta por tarjeta (N+1)**; una prueba lo comprueba contando consultas con 20 eventos.

**Servicio de lectura:** una clase en `app/Support/Events/` (por ejemplo `EventCardSummary`) que, dados los eventos de la página, devuelve por evento el resumen de la convocatoria y los conteos. La tabla solo da formato; el cálculo no vive en la vista.

**Textos:** claves nuevas en `lang/es` y `lang/en` (estado de la convocatoria, «cierra en N días», «N propuestas · M sin decisión», «Publicar», «Abrir»), sin textos fijos en la clase.

**Sin rutas JSON ni webhooks nuevos.**

## 5. Permisos, aislamiento y datos personales

| Pregunta | Respuesta |
|---|---|
| ¿Qué roles pueden hacer qué? | Ver la tarjeta: quien ya ve la lista de eventos. Ver los conteos de propuestas: solo quien puede ver propuestas (`SubmissionPolicy`); sin ese permiso, la tarjeta no muestra esa línea. Publicar: quien ya puede (CA-015-7) |
| ¿Qué Policy cubre cada acción y dónde se prueba? | `EventPolicy` (publicar) y `SubmissionPolicy` (conteos); una prueba por rol, permitido y denegado |
| ¿Hay modelos nuevos con datos de una organización? | No |
| ¿El acceso cruza eventos o depende de `event_assignments`? | Sí, indirectamente: la lista de eventos ya se acota por organización y los conteos se calculan solo de los eventos visibles. Una prueba verifica que no se cuentan propuestas de otro evento ni de otra organización |
| ¿Se guardan datos personales nuevos? | No: solo conteos |
| ¿Se suben archivos? | No |
| ¿Hay dinero, pagos o webhooks? | No |
| ¿Se envían correos o notificaciones? | No |
| ¿Qué se registra en la auditoría? | Nada nuevo: **Publicar** usa la Action que ya audita |

## 6. Estrategia por fases

Cada fase deja el repo en verde y termina en su propio commit.

| Fase | Qué se hace | Esfuerzo |
|---|---|---|
| F0 | `EventCardSummary` con sus pruebas (convocatoria más relevante, conteos, permisos, aislamiento, sin N+1). Sin tocar la tarjeta | M |
| F1 | Franja operativa en la tarjeta (convocatoria, propuestas y sin decisión) y la acción principal por estado; estados apagado y punteado | M |
| F2 | Filtro por estado y agrupación en Próximos, Pasados y Archivados (DA-5). Verificación en navegador a ~375 px y ~1280 px | S |

## 7. Criterios de aceptación

Cada uno tiene al menos una prueba cuyo nombre lo cita (`CA-024-n: ...`) o declara su comprobación manual con una línea `Manual:` debajo.

- **CA-024-1:** *Dado* un evento con una convocatoria abierta que cierra en 12 días, *cuando* se ve su tarjeta, *entonces* dice «Abierta · cierra en 12 días».
- **CA-024-2:** *Dado* un evento con varias convocatorias, *cuando* se ve su tarjeta, *entonces* resume la más relevante: la abierta con cierre más próximo, si no la programada, si no la cerrada.
- **CA-024-3:** *Dado* un evento sin convocatoria, *cuando* se ve su tarjeta, *entonces* no muestra la línea de convocatoria y no queda un hueco.
- **CA-024-4:** *Dado* un evento con 24 propuestas, 6 de ellas sin decisión, *cuando* un usuario con permiso las ve, *entonces* la tarjeta dice «24 propuestas · 6 sin decisión».
- **CA-024-5:** *Dado* un usuario sin permiso para ver propuestas, *cuando* ve la tarjeta, *entonces* no aparece ninguna cifra de propuestas.
- **CA-024-6:** *Dados* eventos de dos organizaciones, o dos eventos de la misma organización, *cuando* se calculan los conteos, *entonces* cada tarjeta cuenta solo las propuestas de su propio evento.
- **CA-024-7:** *Dada* una lista de 20 eventos, *cuando* se renderiza, *entonces* el número de consultas no crece con el número de eventos.
- **CA-024-8:** *Dado* un borrador y un usuario que puede publicar, *cuando* ve la tarjeta, *entonces* ve **Publicar**; sin ese permiso ve **Abrir** y el servidor rechaza igual la publicación.
- **CA-024-9:** *Dado* un evento publicado, *cuando* se ve su tarjeta, *entonces* la acción principal es **Abrir**, y un archivado no lleva acción visible.
- **CA-024-10:** *Dados* un borrador y un archivado, *cuando* se ven en la lista, *entonces* se distinguen de un publicado sin depender del color (borde punteado y atenuado, con icono y texto del estado).
  Manual: abrir la lista con un evento de cada estado a ~375 px y ~1280 px; comprobar que el borrador y el archivado se distinguen aun en escala de grises, y que el foco del teclado es visible.
- **CA-024-11:** *Dada* una organización con 8 o más eventos, *cuando* se abre la lista, *entonces* se agrupan en Próximos, Pasados y Archivados y puede filtrarse por estado; con menos de 8 la lista sigue plana.

## 8. Riesgos

| Riesgo | Mitigación |
|---|---|
| Consultas por tarjeta (N+1) al calcular convocatoria y conteos | Un solo servicio de lectura por página y la prueba de CA-024-7 |
| «Sin decisión» cambia de significado cuando existan la evaluación y las decisiones (planes 011 y 012) | Se define por estado (DA-1), no por quién la evalúa, y la prueba de CA-024-4 se actualiza con esos planes |
| Mostrar cifras a quien no debe verlas | CA-024-5, con una prueba por rol |
| El agrupado confunde con pocos eventos | Umbral de 8 (DA-5), a revisar con el uso real |
| La tarjeta se sobrecarga | Máximo cuatro líneas; si crece más, se evalúa la opción C (lista compacta) |

## 9. Registro de avance

> Se actualiza al cerrar cada fase, en un commit `docs(plans)`.

| Fase | Commit | Fecha | Notas (salida real de las validaciones) |
|---|---|---|---|
| A (previa) | 7cd8403 | 2026-11-18 | Versión A de la tarjeta, base de este plan: nombre primero, fechas cortas con cuánto falta, ciudad, estado al pie y menú arriba a la derecha sin «Ver». Pruebas de `EventCardsTest` en verde. Pendiente comprobarla en navegador. |
