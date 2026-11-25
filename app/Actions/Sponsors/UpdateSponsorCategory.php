<?php

declare(strict_types=1);

namespace App\Actions\Sponsors;

use App\Models\Organization;
use App\Models\SponsorCategory;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Sponsors\SponsorCategoryRules;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cambia el nombre de una categoría de patrocinador (plan 030, DA-6, CA-030-12). Se ejecuta en la organización
 * DE LA CATEGORÍA y deja auditoría con el nombre anterior y el nuevo.
 *
 * @throws AuthorizationException si quien edita no tiene `sponsors.manage` en la organización de la categoría
 * @throws ValidationException si el nombre falta, es muy largo o ya lo tiene otra categoría del evento
 */
final class UpdateSponsorCategory
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, SponsorCategory $category, array $data): SponsorCategory
    {
        $organization = Organization::query()->findOrFail($category->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $category, $data): SponsorCategory {
            $this->authorizeActor($actor, 'update', $category);

            $name = $data['name'] ?? null;
            $validated = Validator::make(
                ['name' => is_string($name) ? trim($name) : $name],
                SponsorCategoryRules::rulesFor($category->event_id, $category->id),
            )->validate();

            return DB::transaction(function () use ($actor, $category, $validated): SponsorCategory {
                $previous = $category->name;

                $category->forceFill(['name' => $validated['name']])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event('sponsor_category_updated')
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $category->event_id,
                        'old' => ['name' => $previous],
                        'new' => ['name' => $category->name],
                    ])
                    ->log('sponsor_category_updated');

                return $category;
            });
        });
    }
}
