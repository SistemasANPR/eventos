<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Scope global: limita toda consulta a la organización en contexto.
 * Sin contexto lanza `MissingOrganizationContext` (falla cerrada, ADR-0003).
 *
 * @implements Scope<Model>
 */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('organization_id'), CurrentOrganization::id());
    }
}
