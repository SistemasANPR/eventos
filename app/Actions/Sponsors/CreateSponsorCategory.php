<?php

declare(strict_types=1);

namespace App\Actions\Sponsors;

use App\Models\Event;
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
 * Agrega una categoría («Nivel») de patrocinador a un evento (plan 030, DA-6, CA-030-12): queda al final del
 * catálogo y solo existe en ese evento. Se ejecuta en la organización DEL EVENTO y deja auditoría. El nombre se
 * compara sin distinguir mayúsculas ni espacios sobrantes.
 *
 * @throws AuthorizationException si quien crea no tiene `sponsors.manage` en la organización del evento
 * @throws ValidationException si el nombre falta, es muy largo o ya existe en el evento
 */
final class CreateSponsorCategory
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data): SponsorCategory
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data): SponsorCategory {
            $this->authorizeActor($actor, 'create', SponsorCategory::class, $event);

            return DB::transaction(function () use ($actor, $event, $data): SponsorCategory {
                // Se bloquea el evento para que dos altas simultáneas no tomen la misma posición ni el mismo nombre.
                Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

                $name = $data['name'] ?? null;
                $validated = Validator::make(
                    ['name' => is_string($name) ? trim($name) : $name],
                    SponsorCategoryRules::rulesFor($event->id),
                )->validate();

                $category = new SponsorCategory;
                $category->event_id = $event->id;
                $category->forceFill([
                    'name' => $validated['name'],
                    'position' => ((int) SponsorCategory::query()->where('event_id', $event->id)->max('position')) + 1,
                ])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($category)
                    ->event('sponsor_category_created')
                    ->withProperties([
                        'organization_id' => $category->organization_id,
                        'event_id' => $event->id,
                        'name' => $category->name,
                    ])
                    ->log('sponsor_category_created');

                return $category;
            });
        });
    }
}
