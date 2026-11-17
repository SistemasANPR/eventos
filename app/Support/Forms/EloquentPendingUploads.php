<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\SubmissionFileStatus;
use App\Models\SubmissionFile;
use App\Support\Files\UploadToken;
use Illuminate\Support\Str;

/**
 * Implementación real de `PendingUploadRepository` (plan 006 con plan 007): cada carga debe existir,
 * estar pendiente, ser de la organización y estar ligada al token de la sesión del formulario.
 */
final class EloquentPendingUploads implements PendingUploadRepository
{
    public function allBelongTo(array $uploadIds, string $uploadToken, string $organizationId): bool
    {
        $uploadIds = array_values(array_unique($uploadIds));

        // Un identificador mal formado nunca pertenece a nadie (y PostgreSQL fallaria al compararlo con un uuid).
        foreach ($uploadIds as $uploadId) {
            if (! Str::isUuid($uploadId)) {
                return false;
            }
        }

        if ($uploadIds === []) {
            return true;
        }

        $files = SubmissionFile::query()
            ->whereIn('id', $uploadIds)
            ->where('organization_id', $organizationId)
            ->where('status', SubmissionFileStatus::Pending)
            ->get(['id', 'upload_token_hash']);

        if ($files->count() !== count($uploadIds)) {
            return false;
        }

        return $files->every(static fn (SubmissionFile $file): bool => UploadToken::matches($file->upload_token_hash, $uploadToken));
    }
}
