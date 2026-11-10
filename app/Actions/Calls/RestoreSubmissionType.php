<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Models\Organization;
use App\Models\SubmissionType;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Restaura un tipo de propuesta archivado (plan 004, DA-7, CA-004-32): vuelve a
 * ofrecerse en las convocatorias. Es idempotente. Se ejecuta en la organización
 * DEL TIPO y deja auditoría.
 *
 * @throws AuthorizationException si quien restaura no tiene `calls.manage` en la organización del tipo
 */
final class RestoreSubmissionType
{
    use AuthorizesInOrganization;

    public function handle(User $actor, SubmissionType $type): SubmissionType
    {
        $organization = Organization::query()->findOrFail($type->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $type): SubmissionType {
            $this->authorizeActor($actor, 'update', $type);

            if (! $type->isArchived()) {
                return $type;
            }

            return DB::transaction(function () use ($actor, $type): SubmissionType {
                $type->forceFill(['archived_at' => null])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($type)
                    ->event('submission_type_restored')
                    ->withProperties(['organization_id' => $type->organization_id, 'old' => ['archived' => true], 'new' => ['archived' => false]])
                    ->log('submission_type_restored');

                return $type;
            });
        });
    }
}
