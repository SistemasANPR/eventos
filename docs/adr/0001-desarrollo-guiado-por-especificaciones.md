# ADR-0001: Desarrollo guiado por especificaciones (SDD) con código limpio

> ADR de partida de la plantilla: ya viene aceptada. Ajustar solo las rutas y los lugares marcados; la regla se conserva.

- **Fecha:** 2026-11-09 (primer commit del repositorio)
- **Estado:** Aceptada
- **Alcance:** todo el repositorio; toda funcionalidad o módulo nuevo

## Contexto

La disciplina de «documentar primero, cambiar poco y probar» suele quedar repartida: endpoints documentados antes de implementarse, migraciones con propuesta previa, ADR para decisiones costosas, deuda técnica cerrada punto a punto. Sin una regla única no queda claro cómo se construye una funcionalidad nueva, qué significa «terminada» ni cómo se derivan las pruebas de lo que se pidió. Permisos, pagos y aislamiento entre organizaciones multiplican el costo de un requisito mal entendido o sin cobertura. Con agentes de código la disciplina importa más: sin una especificación verificable, cada sesión reinterpreta el pedido.

## Decisión

Toda funcionalidad o módulo nuevo se construye con **desarrollo guiado por especificaciones (SDD)** y las prácticas de código limpio de esta decisión. Orden fijo: especificación → contrato → pruebas → código → verificación. La especificación manda: si la implementación se desvía, primero se corrige la especificación y se vuelve a acordar.

### 1. Especificación (antes del código)

Responde qué se construye y cómo se sabrá que está bien:

- **Problema y alcance:** qué cambia, para quién y qué queda **fuera**.
- **Criterios de aceptación numerados `CA-NNN-n`**, donde `NNN` es el número del plan, de modo que sean únicos en todo el repositorio. Cada uno es verificable por una prueba o por una comprobación manual descrita. Formato: *Dado* un estado, *cuando* ocurre algo, *entonces* se observa un resultado. Incluyen casos de error y límites, no solo el camino feliz.
- **Decisiones abiertas** (`DA-1`...) con la opción recomendada, cerradas antes de implementar.
- **Riesgos y casos sensibles:** permisos, datos personales, dinero, concurrencia, aislamiento entre organizaciones.

Dónde vive, sin crear carpetas nuevas:

| Qué se especifica | Dónde |
|---|---|
| Funcionalidad o módulo (esfuerzo M o L) | Un plan en `docs/plans/` (formato en `0000-plantilla-plan.md`) |
| Cambio pequeño (esfuerzo S) | Los criterios en la descripción del commit o en la propuesta aprobada |
| Endpoint JSON o webhook | `docs/api/endpoints.md`, antes de implementarlo |
| Datos persistentes | La propuesta de `docs/database-design.md` §6 |
| Componente o vista | El catálogo de componentes (`design.md` §1.4) y `design.md` |
| Decisión arquitectónica costosa de deshacer | Un ADR en `docs/adr/` |

### 2. Contrato (antes del código)

Lo que otros consumen, escrito primero y de forma ejecutable:

- Reglas de validación en un **Form Request** con su prueba; esquemas de datos entre Laravel y React en `resources/js/types/`.
- Rutas JSON y webhooks documentados en `docs/api/endpoints.md`.
- Los cambios de contrato son compatibles hacia atrás o se documentan como ruptura.

### 3. Pruebas derivadas de la especificación

- Cada criterio tiene al menos una prueba y su nombre lo cita (`it('CA-012-2: rejects an expired link', ...)`). Un criterio sin prueba declara su comprobación manual con una línea `Manual:`. `composer check:criteria` lo verifica.
- **Prueba primero** (la prueba falla antes del código) en: lógica de negocio, permisos, dinero, aislamiento entre organizaciones y todo bug (prueba de regresión). En el resto es la práctica recomendada.
- Pirámide: muchas pruebas unitarias, algunas de funcionalidad (feature) y pocas de extremo a extremo para los flujos críticos (registro, pago, envío de propuesta). Datos ficticios, sin red ni `.env` real.
- La interfaz visual se verifica además en el navegador (~375 px y ~1280 px).

### 4. Código limpio al implementar

- **Cambio mínimo y coherente** con los criterios. Sin abstracciones anticipadas (YAGNI, KISS) ni refactors fuera de alcance.
- **Nombres que revelan intención**; funciones pequeñas de una responsabilidad; sin duplicación ni código muerto.
- **Comentarios que explican el porqué.**
- **Errores explícitos:** se lanzan con su tipo; nunca se degradan en silencio (un fallo no se convierte en lista vacía).
- **Dependencias hacia dentro:** la lógica de negocio vive en Actions y no depende del framework HTTP, de Filament ni de un proveedor; esos detalles se montan en los bordes.
- **Seguridad por defecto:** denegar por defecto, validar toda entrada en el servidor, no registrar datos personales ni secretos.
- **Convenciones vigiladas por pruebas** (Pest Arch, Vitest) en vez de por revisión.

### 5. Definición de terminado

1. La especificación está actualizada y refleja lo construido.
2. Cada criterio tiene su prueba (o comprobación manual registrada) y pasa.
3. `composer check` y, si toca `resources/js/`, `npm run typecheck`, `lint`, `test` y `build` pasan, y se informa la salida real.
4. Se revisó el diff completo (`git diff --check`): sin cambios ajenos, secretos ni artefactos.
5. Una decisión costosa de revertir tiene su ADR; la deuda dejada está en `docs/sprints/deuda-tecnica.md`.
6. La especificación y el código llegan en el mismo commit o la misma rama.
7. Un modelo nuevo con datos de organización está cubierto por las pruebas de aislamiento.

### Proporcionalidad

Un cambio trivial (corregir un texto, un typo, un formato) no necesita especificación formal. Lo que añada comportamiento, contrato, datos, permisos o dinero sigue el flujo completo.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Disciplina implícita, sin regla única | La regla queda repartida; el «terminado» depende de quién trabaje; los criterios no llegan a pruebas |
| TDD estricto en todo el código | Costo desproporcionado para texto y maquetación; se exige solo donde el riesgo lo justifica |
| Carpeta `docs/specs/` y herramienta de especificaciones | Añade estructura y herramientas; los lugares actuales cubren cada tipo |
| Criterios `CA-n` sin prefijo de plan | Se repiten entre planes y no se pueden verificar automáticamente |

## Consecuencias

**Positivas:** un criterio equivale a una prueba, y es más difícil entregar algo que nadie pidió o dejar un caso de error sin cubrir; un «terminado» único y verificable; las áreas de riesgo quedan cubiertas antes de implementarse; un agente puede trabajar contra criterios en lugar de interpretar.

**Negativas o riesgos:** más trabajo previo por funcionalidad (se mitiga con la proporcionalidad); las especificaciones pueden quedar obsoletas si solo cambia el código (por eso viajan con él y la especificación manda).

**Qué cambia para el equipo:** antes de implementar algo no trivial, se presenta la especificación y se espera aprobación; las pruebas citan `CA-NNN-n`; la lista de §5 sustituye al «terminado» informal.

## Referencias

- `AGENTS.md`, «Desarrollo guiado por especificaciones» y «Documentación y terminado»
- `docs/plans/0000-plantilla-plan.md`, `docs/api/endpoints.md`, `docs/database-design.md`
- `scripts/check-criteria.php`
