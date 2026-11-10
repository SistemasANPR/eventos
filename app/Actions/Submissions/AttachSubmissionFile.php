<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\SubmissionFileStatus;
use App\Models\CallForSubmissions;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Support\Files\UploadToken;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Liga archivos `pending` a una propuesta (plan 007, H-007-1). La invoca el envío
 * de la propuesta (`SubmitSubmission`, plan 006), una vez por campo de archivo y
 * dentro de su transacción: si algo falla, no queda nada adjunto.
 *
 * Un archivo ajeno (de otro token, de otra convocatoria, de otro campo, ya
 * adjunto o inexistente) responde con el MISMO error genérico, para no revelar
 * que existe. Límites: 3 archivos por campo, 10 y 100 MB por propuesta (DA-2, DA-8),
 * contando lo ya adjunto a la propuesta.
 *
 * `$submissionId` es el uuid de la propuesta; la tabla `submissions` (plan 006)
 * aún no existe y por eso la base no tiene clave foránea hacia ella.
 *
 * @throws ValidationException si algún archivo no se puede adjuntar o se superan los límites
 */
final class AttachSubmissionFile
{
    public const MAX_FILES_PER_FIELD = 3;

    public const MAX_FILES_PER_SUBMISSION = 10;

    public const MAX_BYTES_PER_SUBMISSION = 100 * 1024 * 1024;

    /** @param  list<string>  $fileIds */
    public function handle(CallForSubmissions $call, string $submissionId, string $uploadToken, string $fieldKey, array $fileIds): void
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        CurrentOrganization::run($organization, function () use ($call, $submissionId, $uploadToken, $fieldKey, $fileIds): void {
            DB::transaction(function () use ($call, $submissionId, $uploadToken, $fieldKey, $fileIds): void {
                $ids = array_values(array_unique($fileIds));

                if ($ids === []) {
                    return;
                }

                $files = SubmissionFile::query()
                    ->where('call_id', $call->id)
                    ->whereIn('id', $ids)
                    ->lockForUpdate()
                    ->get();

                $attachable = $files->count() === count($ids) && $files->every(
                    static fn (SubmissionFile $file): bool => $file->status === SubmissionFileStatus::Pending
                        && $file->form_field_key === $fieldKey
                        && UploadToken::matches($file->upload_token_hash, $uploadToken),
                );

                if (! $attachable) {
                    throw ValidationException::withMessages(['files' => __('files.cannot_attach')]);
                }

                $this->assertWithinLimits($call, $submissionId, $fieldKey, $files->count(), (int) $files->sum('size_bytes'));

                foreach ($files as $file) {
                    $file->forceFill(['status' => SubmissionFileStatus::Attached, 'submission_id' => $submissionId])->save();
                }
            });
        });
    }

    private function assertWithinLimits(CallForSubmissions $call, string $submissionId, string $fieldKey, int $newFiles, int $newBytes): void
    {
        $attached = SubmissionFile::query()
            ->where('call_id', $call->id)
            ->where('submission_id', $submissionId)
            ->get(['form_field_key', 'size_bytes']);

        $inField = $attached->where('form_field_key', $fieldKey)->count() + $newFiles;

        if ($inField > self::MAX_FILES_PER_FIELD) {
            throw ValidationException::withMessages(['files' => __('files.too_many_in_field', ['max' => self::MAX_FILES_PER_FIELD])]);
        }

        if ($attached->count() + $newFiles > self::MAX_FILES_PER_SUBMISSION) {
            throw ValidationException::withMessages(['files' => __('files.too_many_in_submission', ['max' => self::MAX_FILES_PER_SUBMISSION])]);
        }

        if ((int) $attached->sum('size_bytes') + $newBytes > self::MAX_BYTES_PER_SUBMISSION) {
            throw ValidationException::withMessages(['files' => __('files.submission_too_large', ['max' => self::MAX_BYTES_PER_SUBMISSION / 1024 / 1024 .' MB'])]);
        }
    }
}
