<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Program\DeleteProgramImage;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Quita el logotipo de un evento (plan 027, CA-027-6 y 8): la columna queda vacía, el archivo se borra
 * y se audita `event_logo_removed` con identificadores. El archivo se borra una vez confirmada la
 * escritura (tras la transacción que la contenga, si la llama otra). Un evento sin logotipo no cambia.
 *
 * @throws AuthorizationException si quien quita no tiene `events.manage` en la organización del evento
 */
final class RemoveEventLogo
{
    use AuthorizesInOrganization;

    public function __construct(private readonly DeleteProgramImage $delete) {}

    public function handle(User $actor, Event $event): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event): Event {
            $this->authorizeActor($actor, 'update', $event);

            $removedPath = DB::transaction(function () use ($actor, $event): ?string {
                $current = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail()->logo_path;

                if ($current === null) {
                    return null;
                }

                $event->forceFill(['logo_path' => null])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_logo_removed')
                    ->withProperties(['organization_id' => $event->organization_id, 'event_id' => $event->id])
                    ->log('event_logo_removed');

                return $current;
            });

            if ($removedPath !== null) {
                DB::afterCommit(function () use ($event, $removedPath): void {
                    try {
                        $this->delete->handle($event, $removedPath);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
            }

            return $event;
        });
    }
}
