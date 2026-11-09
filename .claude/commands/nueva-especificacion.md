---
description: Redacta la especificación (plan SDD) de una funcionalidad antes de escribir código
argument-hint: <descripción de la funcionalidad>
---

Vamos a especificar, no a implementar. No escribas código ni migraciones.

Funcionalidad pedida: $ARGUMENTS

1. Lee `docs/adr/0001-desarrollo-guiado-por-especificaciones.md`, `docs/plans/0000-plantilla-plan.md`, `docs/producto/glosario.md` y los ADR 0003 y 0005. Si toca pagos, datos personales o archivos, lee también `docs/seguridad.md` y `docs/privacidad.md`.
2. Revisa `docs/plans/` para calcular el siguiente número `NNN` y comprobar que no exista ya un plan que cubra esto. Revisa `docs/sprints/deuda-tecnica.md` por deuda relacionada.
3. Inspecciona el código y las migraciones relacionados para el «Punto de partida»: hechos, no suposiciones.
4. Redacta el plan en `docs/plans/AAAA-MM-DD-NNN-tipo-titulo-en-kebab-case.md` con la plantilla:
   - criterios `CA-NNN-n` verificables, con casos de error y límites;
   - la sección de permisos, aislamiento entre organizaciones y datos personales;
   - decisiones abiertas `DA-n`, cada una con tu recomendación;
   - fases que dejan el repo en verde;
   - riesgos.
5. Estado: `Borrador`. Muéstrame un resumen de cinco líneas, las `DA-n` que necesito cerrar y qué queda fuera. Espera mi aprobación antes de cualquier implementación.
