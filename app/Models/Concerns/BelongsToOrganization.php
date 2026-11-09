<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\Exceptions\OrganizationMismatch;
use App\Support\Tenancy\OrganizationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo con datos de una organización (ADR-0003).
 *
 * - Scope global que falla cerrado sin contexto.
 * - Al crear, asigna la organización del contexto; sin contexto lanza
 *   `MissingOrganizationContext`. Con `organization_id` explícito (fábricas,
 *   plataforma) solo se acepta si coincide con el contexto, cuando lo hay.
 * - `organization_id` no es asignable en masa ni cambia después de creado.
 *
 * @mixin Model
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(static function (Model $model): void {
            $own = $model->getAttribute('organization_id');

            if ($own === null) {
                $model->setAttribute('organization_id', CurrentOrganization::id());

                return;
            }

            if (CurrentOrganization::has() && $own !== CurrentOrganization::id()) {
                throw new OrganizationMismatch('No se puede crear un registro de otra organización distinta de la del contexto');
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('organization_id')) {
                throw new OrganizationMismatch('La organización de un registro no se puede cambiar');
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
