<?php

declare(strict_types=1);

namespace App\Actions\Sponsors;

use App\Models\Organization;
use App\Models\Sponsor;
use App\Models\SponsorCategory;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina una categoría de patrocinador (plan 030, DA-6, CA-030-13). Solo `org_admin` (`sponsors.delete`). Una
 * categoría es solo una etiqueta, como la de un ponente: borrarla NO borra a los patrocinadores que la tenían,
 * que quedan sin nivel. Se ejecuta en la organización DE LA CATEGORÍA, en una transacción, y deja auditoría con
 * el nombre y cuántos patrocinadores quedaron sin nivel.
 *
 * @throws AuthorizationException si quien elimina no tiene `sponsors.delete` en la organización de la categoría
 */
final class DeleteSponsorCategory
{
    use AuthorizesInOrganization;

    public function handle(User $actor, SponsorCategory $category): void
    {
        $organization = Organization::query()->findOrFail($category->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $category): void {
            $this->authorizeActor($actor, 'delete', $category);

            DB::transaction(function () use ($actor, $category): void {
                $snapshot = $category->name;
                $released = Sponsor::query()->where('sponsor_category_id', $category->id)->update(['sponsor_category_id' => null]);

                $category->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event('sponsor_category_deleted')
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $category->event_id,
                        'old' => ['name' => $snapshot],
                        'sponsors_without_level' => $released,
                    ])
                    ->log('sponsor_category_deleted');
            });
        });
    }
}
