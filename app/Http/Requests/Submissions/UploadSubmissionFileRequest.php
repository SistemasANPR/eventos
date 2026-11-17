<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Support\Http\ApiEnvelope;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * Forma de la subida pública de un archivo de propuesta (plan 007, H-007-1).
 * Solo valida que lleguen un archivo y una clave de campo bien formada; el tipo
 * real, la estructura y el tamaño los comprueba `PrivateUploads` sobre el
 * contenido (una sola lectura del archivo). El `upload_token` no es obligatorio
 * aquí: su ausencia es un 403 (CA-007-10), no un 422.
 *
 * La ruta es pública: no hay usuario que autorizar; el acceso lo acota la
 * convocatoria abierta, el token y las cuotas (`UploadSubmissionFile`).
 */
class UploadSubmissionFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'form_field_key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'upload_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            throw new LogicException('El archivo se valida antes de llegar aquí.');
        }

        return $file;
    }

    /** Responde con el sobre JSON del proyecto en vez de la redirección habitual. */
    protected function failedValidation(Validator $validator): void
    {
        $messages = $validator->errors();

        throw new HttpResponseException(ApiEnvelope::error(
            'VALIDATION_ERROR',
            (string) $messages->first(),
            422,
            array_key_first($messages->messages()),
        ));
    }
}
