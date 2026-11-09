## Qué cambia y por qué

<!-- Una o dos frases. Enlaza el plan: docs/plans/AAAA-MM-DD-NNN-... -->

## Criterios de aceptación

<!-- Lista los CA-NNN-n que cubre este PR y dónde está la prueba de cada uno. -->

## Checklist

- [ ] La especificación está al día y viaja con el código
- [ ] `composer check` pasa (adjunta la salida si falló algo previo a este PR)
- [ ] `npm run typecheck`, `lint`, `test` y `build` pasan (si toca `resources/js/`)
- [ ] Modelo nuevo con datos de organización → cubierto en `tests/Feature/Tenancy/`
- [ ] Cada acción nueva tiene su Policy y una prueba por rol (permitido y denegado)
- [ ] Sin secretos, logs ni artefactos en el diff (`git diff --check`)
- [ ] Migración nueva: propuesta aprobada, plan de reversa descrito y **aplicada solo por una persona**
- [ ] Datos personales nuevos → `docs/privacidad.md` actualizado
- [ ] Decisión costosa de revertir → ADR; deuda dejada → `docs/sprints/deuda-tecnica.md`

## Riesgos y cómo verificarlo

<!-- Qué podría romperse, qué datos toca, cómo probarlo a mano (rutas, a 375 px y 1280 px si hay UI). -->
