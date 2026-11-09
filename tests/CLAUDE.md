# Pruebas — reglas para `tests/`

> Complementa `AGENTS.md`. Se usa Pest.

## 1. Estructura

```
tests/
├── Arch/              pruebas de arquitectura (guardianas)
├── Feature/           comportamiento de punta a punta dentro de Laravel
│   ├── Tenancy/       aislamiento entre organizaciones (guardianas)
│   └── <Módulo>/      una carpeta por módulo
├── Unit/              lógica pura (Money, validadores, Actions sin framework)
└── Support/           ayudas: TenantModels, fábricas auxiliares
```

## 2. Nombres y criterios de aceptación

Cada criterio de un plan tiene al menos una prueba cuyo nombre lo cita:

```php
it('CA-012-2: rejects a submission after the call closes', function () { ... });
```

- El formato es `CA-NNN-n`, con `NNN` = número del plan. `composer check:criteria` **falla** si un plan *Terminado* tiene un criterio sin prueba que lo cite, y **avisa** si el plan está *En curso* (`STRICT=1` lo convierte en fallo).
- Una comprobación manual se declara en el plan con la línea `Manual:` bajo el criterio; el script la acepta.
- Descripción de la prueba en inglés (código); el criterio en el plan está en español.

## 3. Prueba primero

Obligatoria en: lógica de negocio, **autorización**, **dinero**, **aislamiento entre organizaciones** y todo bug. Para un bug: la prueba de regresión falla antes del arreglo y pasa después.

## 4. Qué se prueba en cada módulo

- **Una prueba por rol y acción** en toda Policy: permitido y denegado.
- **Aislamiento:** todo modelo nuevo con datos de organización entra a las pruebas de `Feature/Tenancy/`. Su fábrica debe poder crearse con un `organization_id` explícito.
- **Validación:** casos válidos, inválidos y límites de cada Form Request.
- **Actions:** el caso base, los errores de dominio y la atomicidad (si falla el segundo paso, no queda el primero).
- **Webhooks y jobs:** idempotencia (procesar dos veces produce el mismo resultado) y firma inválida.
- **Archivos:** tipo no permitido, tamaño excedido y acceso a un archivo de otra organización.

## 5. Reglas técnicas

- Pruebas deterministas, con datos de fábricas. Prohibido depender de `.env` real, de la red o de servicios externos: `Http::fake()`, `Mail::fake()`, `Queue::fake()`, `Storage::fake()`, `Event::fake()`.
- Base de datos de pruebas en PostgreSQL (igual que producción). `RefreshDatabase` o `DatabaseTransactions` según el caso.
- Limpiar el contexto de organización entre pruebas (`CurrentOrganization::forget()` en `afterEach`).
- Estructura Arrange / Act / Assert.
- Sin `sleep()`. El tiempo se controla con `Carbon::setTestNow()` / `travel()`.
- Las pruebas pasan Larastan: no se excluye `tests/` del análisis.

## 6. Guardianas incluidas

| Archivo | Vigila |
|---|---|
| `Arch/ArchTest.php` | Sin `dd`/`dump`, `env()` fuera de `config/`, sin SQL en controladores, estructura de Actions, Policies y Requests |
| `Feature/Tenancy/ModelsAreScopedTest.php` | Todo modelo usa `BelongsToOrganization` o está en la lista explícita de globales |
| `Feature/Tenancy/TenantIsolationTest.php` | Una organización no ve datos de otra; sin contexto, la consulta falla |

Si una guardiana empieza a fallar, se corrige el código. No se debilita la prueba sin una decisión registrada.

Estas guardianas asumen las convenciones del ADR-0003 (`App\Support\Tenancy\CurrentOrganization`, `App\Models\Concerns\BelongsToOrganization`, `App\Support\Tenancy\Exceptions\MissingOrganizationContext`). Se implementan en el plan `001` (`docs/plans/2026-11-09-001-feat-nucleo-multitenant.md`); hasta entonces fallarán por clases inexistentes, lo cual es esperado.

## 7. Cobertura

Con umbral en CI: el valor medido redondeado hacia abajo. Sube cuando mejora y nunca baja.
