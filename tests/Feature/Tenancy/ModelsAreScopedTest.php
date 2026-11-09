<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToOrganization;
use Tests\Support\TenantModels;

/*
 * Guardiana de aislamiento (ADR-0003).
 *
 * Todo modelo de `app/Models` debe usar `BelongsToOrganization` o estar en la
 * lista explícita `TenantModels::GLOBAL_MODELS`. Un modelo nuevo sin una de las
 * dos cosas hace fallar esta prueba: así nadie olvida el filtro de organización.
 */

it('is either organization-scoped or explicitly global', function (string $model): void {
    $isGlobal = in_array($model, TenantModels::GLOBAL_MODELS, true);
    $isScoped = in_array(BelongsToOrganization::class, class_uses_recursive($model), true);

    if (! $isGlobal && ! $isScoped) {
        $this->fail("{$model} debe usar BelongsToOrganization o declararse global en TenantModels::GLOBAL_MODELS.");
    }

    expect(true)->toBeTrue();
})->with(fn (): array => TenantModels::dataset(TenantModels::all()));

it('keeps the global allow-list free of stale entries', function (): void {
    foreach (TenantModels::GLOBAL_MODELS as $model) {
        if (! class_exists($model)) {
            $this->fail("{$model} figura como global pero ya no existe.");
        }
    }

    expect(true)->toBeTrue();
});

it('does not list a scoped model as global', function (): void {
    foreach (TenantModels::GLOBAL_MODELS as $model) {
        if (in_array(BelongsToOrganization::class, class_uses_recursive($model), true)) {
            $this->fail("{$model} usa BelongsToOrganization pero está en la lista de globales.");
        }
    }

    expect(true)->toBeTrue();
});
