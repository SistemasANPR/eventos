<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Models\Organization;
use App\Models\Track;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Restaura un eje temático archivado (plan 004, DA-4): vuelve a ofrecerse en
 * envíos nuevos. Es idempotente. Se ejecuta en la organización DEL EJE y deja
 * auditoría.
 *
 * @throws AuthorizationException si quien restaura no tiene `tracks.manage` en la organización del eje
 */
final class RestoreTrack
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Track $track): Track
    {
        $organization = Organization::query()->findOrFail($track->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $track): Track {
            $this->authorizeActor($actor, 'update', $track);

            if (! $track->isArchived()) {
                return $track;
            }

            return DB::transaction(function () use ($actor, $track): Track {
                $track->forceFill(['archived_at' => null])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($track)
                    ->event('track_restored')
                    ->withProperties(['organization_id' => $track->organization_id, 'old' => ['archived' => true], 'new' => ['archived' => false]])
                    ->log('track_restored');

                return $track;
            });
        });
    }
}
