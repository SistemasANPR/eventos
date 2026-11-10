<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publica un borrador (plan 003, CA-003-6 y -7): exige `events.publish` y que el
 * evento esté completo, es decir, con nombre en su idioma por defecto. Fechas y
 * zona horaria no pueden faltar (la base los exige).
 *
 * @throws AuthorizationException si quien publica no tiene `events.publish`
 * @throws ValidationException si no es un borrador o le falta un dato (indica el campo)
 */
final class PublishEvent
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Event $event): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event): Event {
            $this->authorizeActor($actor, 'publish', $event);

            if ($event->status !== EventStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('events.only_draft_can_be_published')]);
            }

            $defaultName = trim((string) $event->getTranslation('name', $event->default_locale, false));

            if ($defaultName === '') {
                throw ValidationException::withMessages([
                    "name.{$event->default_locale}" => __('events.name_required_in_default_locale', ['locale' => $event->default_locale]),
                ]);
            }

            return DB::transaction(function () use ($actor, $event): Event {
                $event->forceFill(['status' => EventStatus::Published])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_published')
                    ->withProperties(['organization_id' => $event->organization_id])
                    ->log('event_published');

                return $event;
            });
        });
    }
}
