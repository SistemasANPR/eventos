# Convenciones

Detalle y ejemplos de lo que `AGENTS.md` resume. Idioma: ADR-0006 (código en inglés; documentos, comentarios, commits y textos visibles en español). El vocabulario del dominio sale de `docs/producto/glosario.md`.

## 1. PHP y Laravel

| Elemento | Convención | Ejemplo |
|---|---|---|
| Clase de modelo | Singular, `PascalCase` | `Submission`, `Organization` |
| Tabla | Plural, `snake_case` | `submissions`, `organization_user` |
| Columna | `snake_case`; clave foránea `<modelo>_id` | `organization_id`, `submitted_at` |
| Action | Verbo + objeto, método `handle()` | `AcceptSubmission`, `RegisterAttendee` |
| Enum | Singular, casos en `PascalCase` | `SubmissionStatus::Accepted` |
| Policy | `<Modelo>Policy` | `SubmissionPolicy` |
| Form Request | `<Acción><Modelo>Request` | `StoreSubmissionRequest` |
| Job | Verbo en imperativo | `SendAcceptanceNotice` |
| Excepción de dominio | `<Situación>` con código estable | `SubmissionAlreadyClosed` → `SUBMISSION_ALREADY_CLOSED` |
| Recurso de Filament | `<Modelo>Resource` | `SubmissionResource` |
| Ruta web | `kebab-case`, plural | `/events/{event}/call-for-submissions` |
| Ruta con nombre | `punto.separado` | `events.submissions.create` |
| Clave de traducción | `modulo.concepto.detalle` | `submissions.status.accepted` |
| Variable y método | `camelCase`, sin abreviaturas opacas | `acceptedSubmissions`, `closeCall()` |
| Constante | `UPPER_SNAKE` | `MAX_SPEAKERS_PER_PANEL` |

```
✅  AcceptSubmission · SubmissionStatus · acceptedSubmissions
❌  AcceptSub · Status2 · data · temp · utils.php · Helper
```

- `declare(strict_types=1);` en todo archivo. Tipos de parámetros y de retorno siempre.
- Controladores delgados; un caso de uso por Action; consultas en Actions o scopes (`app/CLAUDE.md` §1).
- Comentarios y PHPDoc en español, explicando el porqué.

## 2. Pruebas (Pest)

```php
// El nombre cita el criterio; la descripción en inglés
it('CA-012-2: rejects a submission after the call closes', function () {
    // Arrange
    // Act
    // Assert
});
```

- Archivos: `<Clase>Test.php` en la carpeta que refleja el módulo (`tests/Feature/Submissions/AcceptSubmissionTest.php`).
- Estructura Arrange / Act / Assert. Una idea por prueba.
- Fábricas en `database/factories`, con `organization_id` aceptable.

## 3. TypeScript y React

Ver `resources/js/CLAUDE.md` §3. Resumen: componentes y páginas en `PascalCase`; hooks `useXxx`; archivos de `lib/` en `kebab-case`; `export function` nombrada (salvo `pages/`); variantes como unión de literales; textos por i18n.

## 4. Commits

Conventional Commits: `tipo(scope): descripción`. **Scope en inglés; descripción en español sin tildes** (evita problemas de codificación en herramientas y terminales).

```
feat(submissions): agregar cierre automatico de la convocatoria
fix(tenancy): corregir el contexto en jobs encolados
refactor(payments): extraer la verificacion de firmas de webhooks
test(auth): agregar pruebas de permisos del comite
docs(plans): registrar la fase F1 del plan 003
docs(debt): cerrar el punto 2.1
chore(deps): actualizar filament a la ultima version menor
```

- Tipos: `feat` `fix` `refactor` `test` `docs` `chore` `perf`.
- Prohibido: sin tipo ni scope; mensajes genéricos («fix bug», «cambios»); tildes en la descripción.
- Un commit por cambio lógico. La especificación viaja con su código; el cierre de un punto de deuda (`docs(debt)`) y el avance de un plan (`docs(plans)`) van en commits propios.
- Terminar con la línea de coautoría que indique la sesión de trabajo.
- Política: se hace commit **solo si el usuario lo pide**; `git push` nunca sin petición explícita. El remoto es `SistemasANPR/eventos` y se usa con la cuenta `Antonio1491` (`docs/entorno-local.md` §8).

## 5. Ramas y pull requests

Modelo de ramas (ADR-0008):

| Rama | Para qué | Quién escribe |
|---|---|---|
| **`staging`** | Integración y pruebas: **aquí va todo el desarrollo** | El equipo y el agente (commits solo si el usuario los pide) |
| **`main`** | Producción | Solo recibe lo que pasó el punto de control de `staging`, por pull request con aprobación humana |

- Verificar la rama antes de empezar: `git branch --show-current`. **El agente nunca hace commit ni merge en `main`.**
- Para un trabajo grande se puede abrir una rama corta desde `staging` (`feat/<tema>`, `fix/<tema>`, `docs/<tema>`) que vuelve a `staging` por pull request.
- Una promoción `staging` → `main` es un pull request con el CI en verde, el punto de control del release cumplido y una revisión humana.
- Un pull request por cambio lógico, con la plantilla de `.github/pull_request_template.md`.
- El CI corre en cada pull request y en cada push a `staging` y `main`. Las rutas sensibles (`.github/CODEOWNERS`) requieren revisión humana.
- Protección de ramas en GitHub (ADR-0008, DA-4): `main` con pull request, CI en verde y una revisión; `staging` con CI en verde.
