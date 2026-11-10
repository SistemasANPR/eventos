<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\EventAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita un evento (plan 003, CA-003-3, -5, -6 y -9). Se ejecuta en la
 * organización DEL EVENTO: quien no pertenece a ella no puede editarlo.
 *
 * Cambiar el `slug` de un evento publicado rompe los enlaces ya difundidos:
 * exige confirmación explícita y queda auditado.
 *
 * @throws AuthorizationException si quien edita no tiene `events.manage` en la organización del evento
 * @throws ValidationException si los datos no son válidos o falta confirmar el cambio de `slug`
 */
final class UpdateEvent
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data, bool $confirmSlugChange = false): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data, $confirmSlugChange, $organization): Event {
            $this->authorizeActor($actor, 'update', $event);

            /** @var list<string> $locales */
            $locales = (array) ($data['locales'] ?? []);
            $validated = Validator::make($data, StoreEventRequest::rulesFor($organization->id, $locales, $event->id))->validate();

            $previousSlug = $event->slug;
            $slugChanges = $validated['slug'] !== $previousSlug;

            if ($slugChanges && $event->isPublished() && ! $confirmSlugChange) {
                throw ValidationException::withMessages(['slug' => __('events.slug_change_needs_confirmation')]);
            }

            return DB::transaction(function () use ($actor, $event, $validated, $slugChanges, $previousSlug): Event {
                $event->fill(EventAttributes::fromValidated($validated))->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_updated')
                    ->withProperties(['organization_id' => $event->organization_id])
                    ->log('event_updated');

                if ($slugChanges && $event->isPublished()) {
                    activity()
                        ->causedBy($actor)
                        ->performedOn($event)
                        ->event('event_slug_changed')
                        ->withProperties(['organization_id' => $event->organization_id, 'from' => $previousSlug, 'to' => $event->slug])
                        ->log('event_slug_changed');
                }

                return $event;
            });
        });
    }
}
