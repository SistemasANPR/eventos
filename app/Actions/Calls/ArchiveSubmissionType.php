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
 * Archiva un tipo de propuesta (plan 004, DA-7, CA-004-32): deja de ofrecerse en
 * convocatorias nuevas y de aceptarse al editar para cambiar a él, pero las
 * convocatorias que ya lo tienen lo conservan. Es idempotente. Se ejecuta en la
 * organización DEL TIPO y deja auditoría.
 *
 * @throws AuthorizationException si quien archiva no tiene `calls.manage` en la organización del tipo
 */
final class ArchiveSubmissionType
{
    use AuthorizesInOrganization;

    public function handle(User $actor, SubmissionType $type): SubmissionType
    {
        $organization = Organization::query()->findOrFail($type->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $type): SubmissionType {
            $this->authorizeActor($actor, 'update', $type);

            if ($type->isArchived()) {
                return $type;
            }

            return DB::transaction(function () use ($actor, $type): SubmissionType {
                $type->forceFill(['archived_at' => now()])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($type)
                    ->event('submission_type_archived')
                    ->withProperties(['organization_id' => $type->organization_id, 'old' => ['archived' => false], 'new' => ['archived' => true]])
                    ->log('submission_type_archived');

                return $type;
            });
        });
    }
}
