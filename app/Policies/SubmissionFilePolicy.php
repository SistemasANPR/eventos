<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SubmissionFileStatus;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Support\Auth\ChecksOrganizationPermissions;
use App\Support\Auth\TwoFactorRequirement;
use App\Support\Reviews\BlindReview;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;

/**
 * Quién descarga los archivos de una propuesta (plan 007, DA-4): quien tiene `files.download` con
 * membresía activa en la organización del archivo, y el evaluador a quien se asignó la propuesta
 * (plan 011, H-011-3) mientras la asignación siga vigente (ni cancelada ni en conflicto declarado) y su
 * segundo factor esté confirmado cuando se exige. El autor no descarga en R0. Un archivo `pending`
 * (sin propuesta) no se descarga nunca.
 */
final class SubmissionFilePolicy
{
    use ChecksOrganizationPermissions;

    public function download(User $user, SubmissionFile $file): bool
    {
        if ($file->status !== SubmissionFileStatus::Attached) {
            return false;
        }

        return $this->downloadAsOrganizer($user, $file) || $this->isAssignedReviewer($user, $file);
    }

    /**
     * Descarga por la vía del organizador (`files.download`): nombre generado `uuid.ext` y sin ocultamiento de
     * revisión ciega. Quien descarga solo como evaluador recibe el nombre neutro (plan 011, CA-011-20).
     */
    public function downloadAsOrganizer(User $user, SubmissionFile $file): bool
    {
        return $file->status === SubmissionFileStatus::Attached
            && $this->allows($user, ['files.download'], $file->organization_id);
    }

    private function isAssignedReviewer(User $user, SubmissionFile $file): bool
    {
        if ($file->submission_id === null
            || ! CurrentOrganization::has()
            || $file->organization_id !== CurrentOrganization::id()
            || ! TwoFactorRequirement::isSatisfiedBy($user)) {
            return false;
        }

        $assignment = ReviewAssignment::query()
            ->where('submission_id', $file->submission_id)
            ->where('reviewer_id', $user->id)
            ->first();

        if ($assignment === null || ! Gate::forUser($user)->allows('submit', [Review::class, $assignment])) {
            return false;
        }

        // Revisión ciega (plan 011, CA-011-18): el archivo de un campo que identifica al autor no se entrega,
        // aunque se conozca su identificador. Sin la propuesta no hay con qué decidirlo: se niega.
        $submission = $assignment->submission;

        return $submission !== null && ! BlindReview::hidesFile($submission, $file);
    }
}
