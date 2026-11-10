<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Registro de la bitácora con la organización a la que pertenece el hecho (plan
 * 016, DT-01). Es un modelo de plataforma, no de una organización: los hechos de
 * plataforma tienen `organization_id` nulo, así que no usa `BelongsToOrganization`
 * y se declara global en `TenantModels::GLOBAL_MODELS`. Ninguna pantalla de una
 * organización lo consulta (DT-01 pide acotarlo antes de eso).
 *
 * La organización sale de `properties.organization_id` (que ya pone cada Action) y,
 * si no está, del contexto en curso. Un valor que no es un uuid se ignora: la
 * auditoría nunca debe hacer fallar la operación que registra.
 *
 * @property string|null $organization_id
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            $activity->organization_id ??= self::organizationIdOf($activity);
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    private static function organizationIdOf(self $activity): ?string
    {
        $fromProperties = $activity->properties?->get('organization_id');

        if (is_string($fromProperties) && Str::isUuid($fromProperties)) {
            return $fromProperties;
        }

        return CurrentOrganization::has() ? CurrentOrganization::id() : null;
    }
}
