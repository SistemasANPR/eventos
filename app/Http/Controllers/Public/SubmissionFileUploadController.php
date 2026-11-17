<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Submissions\UploadSubmissionFile;
use App\Exceptions\Domain\UploadQuotaExceeded;
use App\Exceptions\Domain\UploadTokenInvalid;
use App\Http\Requests\Submissions\UploadSubmissionFileRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Events\PublicEventData;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Subida pública de un archivo de propuesta:
 * `POST /{organization:slug}/events/{event:slug}/call/{call}/files`.
 * La organización queda en contexto por `SetOrganizationFromRoute` y la
 * convocatoria se resuelve acotada a ella y al evento (otra organización u otro
 * evento responde 404). Responde con el sobre JSON del proyecto.
 */
final class SubmissionFileUploadController
{
    public function __invoke(
        UploadSubmissionFileRequest $request,
        Organization $organization,
        Event $event,
        CallForSubmissions $call,
        UploadSubmissionFile $upload,
    ): JsonResponse {
        abort_unless($event->isPublished(), 404);

        app()->setLocale(PublicEventData::resolveLocale($request, $event));

        try {
            $file = $upload->handle(
                $call,
                $request->uploadedFile(),
                $request->string('form_field_key')->toString(),
                $request->string('upload_token')->toString(),
                (string) $request->ip(),
            );
        } catch (UploadTokenInvalid $exception) {
            return ApiEnvelope::error(UploadTokenInvalid::CODE, $exception->getMessage(), 403);
        } catch (UploadQuotaExceeded $exception) {
            return ApiEnvelope::error(UploadQuotaExceeded::CODE, $exception->getMessage(), 429, headers: $this->retryAfter($exception));
        } catch (ValidationException $exception) {
            return ApiEnvelope::error('VALIDATION_ERROR', (string) collect($exception->errors())->flatten()->first(), 422, array_key_first($exception->errors()));
        }

        return ApiEnvelope::success(['id' => $file->id], 201);
    }

    /** @return array<string, string> */
    private function retryAfter(UploadQuotaExceeded $exception): array
    {
        $seconds = $exception->getHeaders()['Retry-After'] ?? null;

        return is_string($seconds) ? ['Retry-After' => $seconds] : [];
    }
}
