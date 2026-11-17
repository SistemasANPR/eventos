<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\CallState;
use App\Enums\SubmissionFileStatus;
use App\Exceptions\Domain\PrivateUploadFailed;
use App\Exceptions\Domain\UploadQuotaExceeded;
use App\Exceptions\Domain\UploadTokenInvalid;
use App\Models\CallForSubmissions;
use App\Models\Organization;
use App\Models\SubmissionFile;
use App\Support\Calls\ResolveCallState;
use App\Support\Files\FileFieldRegistry;
use App\Support\Files\PrivateUploads;
use App\Support\Files\UploadQuota;
use App\Support\Files\UploadToken;
use App\Support\Files\UploadTokenVerifier;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sube un archivo a una propuesta aún no enviada (plan 007, H-007-1). Es una
 * acción PÚBLICA (sin usuario): se acota por convocatoria abierta, `upload_token`
 * emitido por la sesión del formulario y cuotas por token y por IP. El archivo
 * queda `pending` hasta que `AttachSubmissionFile` lo liga a la propuesta.
 *
 * Orden: token, cuota, convocatoria abierta, campo del formulario y, al final,
 * el contenido. Un archivo rechazado no deja nada en el disco ni en la base.
 *
 * @throws UploadTokenInvalid sin token o con uno que la sesión del formulario no emitió (403, `UPLOAD_TOKEN_INVALID`)
 * @throws UploadQuotaExceeded si el token o la IP superaron su cuota (429, `UPLOAD_QUOTA_EXCEEDED`)
 * @throws ValidationException convocatoria no abierta, campo inexistente o archivo no aceptable
 * @throws PrivateUploadFailed si el disco falla
 */
final class UploadSubmissionFile
{
    public function __construct(
        private readonly PrivateUploads $uploads,
        private readonly FileFieldRegistry $fields,
        private readonly UploadTokenVerifier $tokens,
        private readonly UploadQuota $quota,
    ) {}

    public function handle(CallForSubmissions $call, UploadedFile $file, string $fieldKey, ?string $uploadToken, string $ip): SubmissionFile
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($call, $file, $fieldKey, $uploadToken, $ip): SubmissionFile {
            if ($uploadToken === null || $uploadToken === '' || ! $this->tokens->isIssued($call, $uploadToken)) {
                throw UploadTokenInvalid::make();
            }

            $this->quota->hit($call->organization_id, $uploadToken, $ip);

            if (ResolveCallState::for($call) !== CallState::Open) {
                throw ValidationException::withMessages(['call' => __('files.call_not_open')]);
            }

            if (! $this->fields->exists($call, $fieldKey)) {
                throw ValidationException::withMessages(['form_field_key' => __('files.field_not_found')]);
            }

            $inspected = $this->uploads->inspect($file);
            $id = (string) Str::uuid();
            $path = $this->uploads->store($inspected, $call->event_id, $id);

            try {
                $record = new SubmissionFile;
                $record->forceFill([
                    'id' => $id,
                    'event_id' => $call->event_id,
                    'call_id' => $call->id,
                    'submission_id' => null,
                    'form_field_key' => $fieldKey,
                    'upload_token_hash' => UploadToken::hash($uploadToken),
                    'storage_path' => $path,
                    'original_name' => $inspected->originalName,
                    'mime_type' => $inspected->mimeType,
                    'size_bytes' => $inspected->sizeBytes,
                    'sha256' => $inspected->sha256,
                    'status' => SubmissionFileStatus::Pending,
                    'uploaded_at' => Date::now(),
                ])->save();
            } catch (Throwable $exception) {
                // Sin ficha en la base no debe quedar el archivo suelto en el disco.
                $this->uploads->delete($path);

                throw $exception;
            }

            return $record;
        });
    }
}
