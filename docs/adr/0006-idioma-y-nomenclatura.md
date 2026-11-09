# ADR-0006: Idioma del código y de la documentación

- **Fecha:** 2026-11-09
- **Estado:** Aceptada
- **Alcance:** todo el repositorio

## Contexto

La plantilla de origen exigía identificadores en español sin tildes. En Laravel eso choca con las convenciones del framework y de sus paquetes (modelos, políticas, Form Requests, recursos de Filament, tablas y claves foráneas), mezcla español e inglés en el mismo archivo y dificulta que agentes y personas de otros países trabajen el código. El producto se ofrecerá como SaaS a otras organizaciones, posiblemente fuera de México.

## Decisión

| Elemento | Idioma |
|---|---|
| Identificadores (clases, métodos, variables, tablas, columnas, rutas, claves de traducción) | **Inglés** |
| Nombres de archivos de código | **Inglés** |
| Documentos (`docs/`, `AGENTS.md`, `CLAUDE.md`), planes y ADR | **Español** |
| Comentarios y PHPDoc | **Español** (explican el porqué) |
| Mensajes de commit | Conventional Commits; scope en inglés; descripción en **español sin tildes** |
| Textos visibles por el usuario | Por i18n: español (base), inglés y portugués en `lang/` |
| Vocabulario del dominio | `docs/producto/glosario.md` fija la traducción de cada término (p. ej. propuesta → `Submission`) |

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Todo en español, como la plantilla de origen | Choca con las convenciones del framework y de los paquetes; mezcla idiomas |
| Dominio en español y el resto en inglés | Mezcla de idiomas en un mismo archivo y glosario más difícil de mantener |
| Documentación también en inglés | El equipo y los primeros usuarios trabajan en español; se puede traducir más adelante |

## Consecuencias

**Positivas:** código consistente con Laravel y con el ecosistema; más fácil de contratar y de integrar; los agentes rinden mejor con nombres convencionales.

**Negativas o riesgos:** el equipo debe mantener un glosario para no inventar traducciones distintas del mismo concepto.

**Qué cambia para el equipo:** `docs/producto/glosario.md` es obligatorio al nombrar modelos o tablas; `docs/convenciones.md` lista los patrones.

## Referencias

- `docs/convenciones.md`, `docs/producto/glosario.md`
