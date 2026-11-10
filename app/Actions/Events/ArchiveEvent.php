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
 * Archiva un evento publicado (plan 003, CA-003-6 y -8): desaparece del acceso
 * público y conserva todos sus datos. Archivar es un estado, no un borrado: el
 * evento nunca se elimina físicamente.
 *
 * @throws AuthorizationException si quien archiva no tiene `events.publish`
 * @throws ValidationException si el evento no está publicado
 */
final class ArchiveEvent
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Event $event): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event): Event {
            $this->authorizeActor($actor, 'archive', $event);

            if ($event->status !== EventStatus::Published) {
                throw ValidationException::withMessages(['status' => __('events.only_published_can_be_archived')]);
            }

            return DB::transaction(function () use ($actor, $event): Event {
                $event->forceFill(['status' => EventStatus::Archived])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_archived')
                    ->withProperties(['organization_id' => $event->organization_id])
                    ->log('event_archived');

                return $event;
            });
        });
    }
}
