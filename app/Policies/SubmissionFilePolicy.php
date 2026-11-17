<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SubmissionFileStatus;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;

/**
 * Quién descarga los archivos de una propuesta (plan 007, DA-4): solo quien tiene
 * `files.download` con membresía activa en la organización del archivo. El autor
 * no descarga en R0. Un archivo `pending` (sin propuesta) no se descarga nunca.
 */
final class SubmissionFilePolicy
{
    use ChecksOrganizationPermissions;

    public function download(User $user, SubmissionFile $file): bool
    {
        return $file->status === SubmissionFileStatus::Attached
            && $this->allows($user, ['files.download'], $file->organization_id);
    }
}
