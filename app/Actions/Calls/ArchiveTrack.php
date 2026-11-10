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
 * Archiva un eje temático (plan 004, DA-4, CA-004-14): deja de ofrecerse en
 * envíos nuevos y se conserva en las propuestas existentes, esté o no en uso.
 * Es idempotente. Se ejecuta en la organización DEL EJE y deja auditoría.
 *
 * @throws AuthorizationException si quien archiva no tiene `tracks.manage` en la organización del eje
 */
final class ArchiveTrack
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Track $track): Track
    {
        $organization = Organization::query()->findOrFail($track->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $track): Track {
            $this->authorizeActor($actor, 'update', $track);

            if ($track->isArchived()) {
                return $track;
            }

            return DB::transaction(function () use ($actor, $track): Track {
                $track->forceFill(['archived_at' => now()])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($track)
                    ->event('track_archived')
                    ->withProperties(['organization_id' => $track->organization_id, 'old' => ['archived' => false], 'new' => ['archived' => true]])
                    ->log('track_archived');

                return $track;
            });
        });
    }
}
