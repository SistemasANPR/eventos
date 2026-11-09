# AGENTS.md

Reglas estables para agentes de código en `[RELLENAR: nombre del repo]`. Sin estado de sesión: el estado vive en Git y en `docs/plans/`.

## Producto e invariantes

[RELLENAR: una o dos frases que describan el producto.]

Plataforma SaaS multi-organización para gestionar eventos. Cada cambio protege primero, en este orden:

1. **Aislamiento entre organizaciones:** una organización nunca ve ni modifica datos de otra.
2. **Autorización en el servidor:** nunca basta con ocultar un menú o un botón.
3. **Integridad de pagos y datos personales.**
4. [RELLENAR: invariante propia, o borrar esta línea.]

## Fuentes de verdad y contexto

- Antes de editar, revisar `git status --short` y preservar los cambios del usuario aunque no estén relacionados.
- Son fuentes de verdad ejecutables: `composer.json`, `package.json`, `phpstan.neon`, `pint.json`, `config/`, `routes/` y el código vigente.
- Leer según el alcance:

| Alcance | Leer |
|---|---|
| Backend, API, seguridad | `app/CLAUDE.md`, `docs/seguridad.md` |
| Frontend React | `resources/js/CLAUDE.md`, `design.md` |
| Pruebas | `tests/CLAUDE.md` |
| Datos persistentes | `docs/database-design.md`, ADR-0003 y ADR-0005 |
| Funcionalidad o módulo nuevo | `docs/adr/0001-desarrollo-guiado-por-especificaciones.md` |
| Endpoints JSON y webhooks | `docs/api/endpoints.md` |
| Pagos y facturación | ADR-0007, `docs/seguridad.md` §Pagos |
| Datos personales | `docs/privacidad.md` |
| Despliegue y operación | `docs/operacion.md` |
| Vocabulario del dominio | `docs/producto/glosario.md` |
| Entorno local, Docker y ramas | `docs/entorno-local.md`, ADR-0008 |
| Área con deuda abierta | `docs/sprints/deuda-tecnica.md` |

- `docs/plans/` es la especificación vigente de lo que está en curso y el histórico de lo terminado.
- Si una guía contradice la configuración ejecutable o el comportamiento comprobado, no adivinar: conservar el comportamiento seguro, documentar la discrepancia y preguntar solo si cambia materialmente el resultado.

## Arquitectura

Monolito Laravel con tres zonas de interfaz (ADR-0004):

| Zona | Quién | Interfaz |
|---|---|---|
| Panel de organizadores | Equipo de cada organización | Filament |
| Portales con sesión | Ponentes, evaluadores, patrocinadores, voluntarios | Filament, un panel por rol |
| Sitio público | Cualquier visitante | React + Inertia (SSR) |

Jerarquía de datos: **Organización (tenant) → Evento → todo lo demás** (ADR-0003).

```
app/Actions/      un caso de uso por clase, método handle()
app/Enums/        estados y tipos
app/Filament/     un directorio por panel (Admin, Committee, Speaker, ...)
app/Http/         controladores delgados, Requests, middleware
app/Models/       Eloquent; Concerns/ con BelongsToOrganization
app/Policies/     una por modelo
app/Support/      Tenancy, Money y utilidades transversales
resources/js/     sitio público React
lang/             es, en, pt
docs/ · tests/ · scripts/
```

Los artefactos generados (`vendor/`, `node_modules/`, `public/build/`, `storage/`, `coverage/`) y los logs no se editan ni se incluyen en cambios.

## Acciones que requieren permiso explícito

El agente **no** hace esto sin que el humano lo pida. Varias están reforzadas en `.claude/settings.json`.

1. Crear o modificar migraciones sin la propuesta aprobada de `docs/database-design.md` §6, o ejecutarlas contra una base que no sea la local de desarrollo. El agente genera la migración; **solo el humano la aplica a una base real**.
2. Eliminar archivos (excepción: un componente que deja de usarse se borra, y la prueba del catálogo lo exige).
3. Cambiar la firma pública de una Action, Policy o modelo que ya tiene pruebas.
4. Agregar, quitar o actualizar dependencias (composer o npm) fuera de la lista de §Dependencias. Un componente de shadcn/ui se añade con su CLI, nunca a mano.
5. Leer o modificar `.env*`. `.env.example` solo se edita para una variable nueva, con valor ficticio.
6. Cambiar la estructura de carpetas.
7. Crear una ruta JSON pública o un webhook que no esté en `docs/api/endpoints.md`.
8. Modificar archivos fuera del módulo en que se trabaja (salvo traducciones, pruebas y documentación sincronizadas).
9. Cambiar reglas de autorización (Policies, roles, permisos) sin especificación aprobada y pruebas.
10. Saltarse el filtro de organización (`withoutOrganizationScope`) fuera de los casos que lista el ADR-0003.
11. Implementar una funcionalidad nueva sin especificación aprobada (ADR-0001).
12. Hacer push, reescribir historial, hacer **commit o merge en `main`**, o tocar configuración de despliegue, secretos del CI o infraestructura. El desarrollo va en `staging` (ADR-0008).
13. Tocar pagos, comisiones, reembolsos o facturación sin leer el ADR-0007.
14. Modificar `Dockerfile`, `docker-compose.yml` o la configuración del entorno local, o destruir datos locales (`docker compose down -v`, `docker volume rm`, `docker system prune`).
15. [RELLENAR: acción sensible propia del proyecto, o borrar esta línea.]

**Ante cualquier duda sobre el alcance de una tarea, preguntar antes de actuar.**

## Forma de trabajar

- Resolver la causa raíz con el cambio más pequeño y coherente. Sin refactors, renombrados ni reformateos masivos fuera del alcance.
- Buscar primero una implementación análoga en el mismo módulo y respetar su estructura, estilo e imports. Antes de crear un componente React, consultar el catálogo (`design.md` §1.4); antes de un helper, buscar en `app/Support/` y `resources/js/lib/`.
- Trabajar en la rama `staging` (`git branch --show-current` antes de empezar). No crear commits hasta que el usuario lo pida; nunca hacer push sin petición explícita.
- No leer, mostrar, copiar ni versionar secretos.
- Mantener compatibilidad hacia atrás en API y datos, salvo que la tarea exija y documente una ruptura.
- No suprimir errores con `@phpstan-ignore`, `@ts-ignore`, `any`, `eslint-disable` ni casts amplios sin una justificación local y comprobable.

## Desarrollo guiado por especificaciones

Toda funcionalidad o módulo nuevo sigue **SDD** (ADR-0001). Orden fijo: especificación → contrato → pruebas → código → verificación. La especificación manda: si el código se desvía, se corrige primero la especificación.

1. **Especificación.** Problema, alcance (y qué queda fuera) y criterios de aceptación numerados **`CA-NNN-n`**, donde `NNN` es el número del plan. Formato: *Dado / cuando / entonces*, con casos de error y límites. Esfuerzo M o L: plan en `docs/plans/` (`0000-plantilla-plan.md`). Esfuerzo S: en la descripción del commit. Las decisiones abiertas se numeran `DA-n` con su recomendación. Se presenta y se espera aprobación antes de implementar.
2. **Contrato.** Lo que otros consumen, primero y ejecutable: endpoint en `docs/api/endpoints.md`; datos con la propuesta de `docs/database-design.md` §6; reglas de validación en un Form Request con su prueba.
3. **Pruebas.** Cada criterio tiene al menos una prueba cuyo nombre lo cita (`it('CA-012-2: ...')`) o declara su comprobación manual. **Prueba primero** (que falle antes del código) en lógica de negocio, permisos, dinero, aislamiento entre organizaciones y todo bug; en lo demás, recomendable. `composer check:criteria` lo vigila.
4. **Código limpio.** El cambio mínimo que cumple los criterios; nombres que revelan intención; funciones pequeñas de una responsabilidad; sin duplicación, código muerto ni abstracciones anticipadas; comentarios que explican el porqué; errores explícitos; la lógica de negocio no depende del framework HTTP (va en Actions); denegar por defecto.
5. **Terminado.** Ver «Documentación y terminado».

Proporcionalidad: un cambio trivial (texto, typo, formato) no necesita especificación. Todo lo que añada comportamiento, contrato, datos, permisos o dinero sigue el flujo completo. Un trabajo grande se hace por fases, cada una deja el repo en verde y termina en su commit; el plan registra el hash de cada fase.

## Convenciones de código

Detalle y ejemplos en `docs/convenciones.md`.

- **Idioma (ADR-0006):** identificadores, archivos y rutas en inglés; documentos, comentarios, mensajes de commit y textos visibles en español. Los términos del dominio siguen `docs/producto/glosario.md`.
- PHP: `declare(strict_types=1)` en todo archivo, formato de Pint (preset Laravel), análisis con Larastan. Respetar el nivel de `phpstan.neon`; el nivel solo sube.
- TypeScript estricto en el frontend; sin `any`.
- Toda convención comprobable mecánicamente se vigila con una prueba guardiana (Pest Arch en PHP, Vitest en React), en vez de confiar en la revisión. Al cerrar un punto de deuda de convenciones, añadir la prueba que impide reabrirlo.
- Evitar abreviaturas opacas y utilidades genéricas. Extraer una abstracción solo con reutilización o reducción de complejidad reales.

## Backend, datos y seguridad

Reglas completas en `app/CLAUDE.md` y `docs/seguridad.md`. Las que no admiten excepción:

- Todo modelo con datos de una organización usa `BelongsToOrganization` (ADR-0003). Sin contexto de organización, la consulta **falla**, no devuelve vacío.
- Toda entrada se valida en el servidor con un Form Request; nunca `$request->all()`.
- Toda acción se autoriza con una Policy en el servidor.
- Dinero en unidades menores enteras con código de moneda; fechas en UTC.
- Listas paginadas, con máximo 100.
- Escrituras de varios pasos dentro de una transacción; webhooks y operaciones externas idempotentes, con firma verificada.
- Archivos de usuarios en disco privado, con nombre generado.
- Los fallos se ven: un error de base o de proveedor se propaga; nunca se degrada a lista vacía.

## Frontend

Reglas completas en `resources/js/CLAUDE.md` y `design.md`. Mobile-first; estados de carga, vacío, error, éxito y permisos; todo texto visible por i18n; fechas, números y monedas con `Intl`; accesibilidad AA; sin secretos en variables públicas del bundler.

## Dependencias

Autorizadas desde el inicio. Cualquier otra exige permiso (acción 4). Mantener `composer.lock` y `package-lock.json` sincronizados.

| Ámbito | Paquetes |
|---|---|
| Backend | `laravel/framework`, `filament/filament`, `inertiajs/inertia-laravel`, `spatie/laravel-permission`, `spatie/laravel-activitylog`, `spatie/laravel-medialibrary`, `spatie/laravel-translatable`, `laravel/horizon` |
| Backend (sujeto a ADR) | `laravel/cashier` o SDK de pagos (ADR-0007); cliente de errores como Sentry (`docs/operacion.md`) |
| Informes | `maatwebsite/excel`, `barryvdh/laravel-dompdf` |
| Calidad | `pestphp/pest` (con Arch), `larastan/larastan`, `laravel/pint` |
| Entorno local | Docker y `laravel/sail` (solo desarrollo, ADR-0008) |
| Frontend | `react`, `@inertiajs/react`, `typescript`, `tailwindcss`, shadcn/ui (vía CLI), `vitest`, `@testing-library/react`, `eslint`, `prettier` |

## Pruebas y validación

Usar la comprobación mínima suficiente y ampliarla según el riesgo. No afirmar que un comando pasó si no se ejecutó o si falló por configuración previa.

Todo corre **dentro del contenedor**: prefijar cada comando con `docker compose exec app` (ADR-0008, `docs/entorno-local.md`). No usar el PHP, Composer ni Node del equipo.

```bash
# Solo comandos que existan en composer.json y package.json. Borrar los que no.
composer check            # lint + análisis estático + pruebas + criterios
composer test             # Pest
composer test -- --filter=NombreDeLaPrueba
composer analyse          # Larastan
composer lint             # Pint en modo comprobación
composer format           # Pint
composer test:coverage    # falla si baja del umbral
composer check:criteria   # cada CA-NNN-n con su prueba
npm run typecheck && npm run lint && npm run test && npm run build
```

- No inventar una validación ni descargar herramientas con `npx` o `composer global`. Usar lo declarado o informar la limitación.
- Backend: primero la prueba enfocada y el análisis; ampliar a la suite si toca contratos, seguridad, pagos o código transversal.
- Frontend: al menos `typecheck` y `build`; verificar en navegador a ~375 px y ~1280 px cuando cambie layout o interacción, y mirar la consola sin errores.
- Cobertura con umbral en CI, fijado al valor medido redondeado hacia abajo: sube, nunca baja.
- Pruebas deterministas, con datos ficticios, sin depender de `.env` real, red ni servicios externos (usar `Http::fake()`, `Mail::fake()`, `Queue::fake()`, `Storage::fake()`).
- Todo arreglo de bug lleva una prueba de regresión.
- Al final: `git diff --check`, revisar el diff completo y confirmar que no hay secretos, logs, artefactos ni cambios ajenos.

## Documentación y terminado

- Actualizar contratos, `.env.example`, traducciones o documentación solo cuando cambie el comportamiento público.
- Registrar un ADR para decisiones arquitectónicas costosas de revertir; no para implementaciones locales rutinarias.
- Una tarea está terminada cuando lo pedido está implementado, los riesgos relevantes tienen cobertura, las validaciones proporcionales pasan y se informan las que no se ejecutaron.
- Una **funcionalidad o módulo nuevo** está terminado cuando, además: la especificación está al día y viaja con el código; cada criterio tiene su prueba (o comprobación manual registrada) y pasa; `composer check` y, si toca el frontend, `npm run typecheck`, `lint`, `test` y `build` pasan con salida real; hay una prueba de aislamiento si se añadió un modelo con datos de organización; se revisó el diff; las decisiones costosas tienen ADR y la deuda dejada está en `docs/sprints/deuda-tecnica.md`.
- Al cerrar un punto de deuda, moverlo a «Resueltos» con su commit, en un commit `docs(debt)` aparte.
- Commits: Conventional Commits; scope en inglés; descripción en español sin tildes (`docs/convenciones.md`).

## Code Review Rules

Priorizar defectos comprobables sobre preferencias de estilo:

- fuga entre organizaciones: consultas sin filtro, `withoutOrganizationScope`, route-model binding sin acotar, caches sin clave de organización;
- autorización ausente o solo en la interfaz; IDOR;
- entrada sin validar, asignación masiva, SQL con interpolación, salida sin escapar;
- subida de archivos sin validar o servida desde una ruta pública;
- integridad e idempotencia de pagos, webhooks, correos y reintentos;
- migraciones destructivas, compatibilidad de datos y falta de plan de reversa;
- degradar un fallo a lista vacía en vez de propagarlo;
- criterios `CA-NNN-n` sin prueba;
- condiciones de carrera, escrituras parciales y manejo incorrecto de errores;
- falta de pruebas para comportamiento nuevo o regresiones plausibles.

Cada hallazgo indica archivo y línea, impacto, escenario que lo reproduce y una corrección segura. Si no hay hallazgos, decirlo y mencionar riesgos residuales o validaciones no ejecutadas.
