<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Exceptions\Domain\TrackInUse;
use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Calls\TrackUsageChecker;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Elimina un eje temático que nadie usa (plan 004, DA-4, CA-004-14). Si
 * `TrackUsageChecker` lo declara en uso se rechaza con `TRACK_IN_USE` y solo se
 * puede archivar. El panel no ofrece borrar; esta Action existe para que la
 * regla viva en un solo lugar. Deja auditoría.
 *
 * @throws AuthorizationException si quien elimina no tiene `tracks.manage` en la organización del eje
 * @throws TrackInUse si el eje tiene propuestas
 */
final class DeleteTrack
{
    use AuthorizesInOrganization;

    public function __construct(private readonly TrackUsageChecker $usage) {}

    public function handle(User $actor, Track $track): void
    {
        $organization = Organization::query()->findOrFail($track->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $track): void {
            $this->authorizeActor($actor, 'delete', $track);

            DB::transaction(function () use ($actor, $track): void {
                if ($this->usage->isInUse($track)) {
                    throw new TrackInUse;
                }

                $snapshot = ['name' => $track->getTranslations('name'), 'description' => $track->getTranslations('description')];
                $track->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($track)
                    ->event('track_deleted')
                    ->withProperties(['organization_id' => $track->organization_id, 'event_id' => $track->event_id, 'old' => $snapshot])
                    ->log('track_deleted');
            });
        });
    }
}
