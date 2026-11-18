<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Support\Forms\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editar la propuesta (plan 006, H-006-4). Valida la FORMA del cuerpo; las reglas que dependen de la
 * convocatoria y del formulario vigente (participantes, respuestas, eje activo) las aplica
 * `UpdateSubmissionByAuthor` con su transacción. Solo se leen las claves de `validated()`: el correo, el
 * estado, la convocatoria y la organización no se aceptan (CA-006-45, DA-6). La autorización es la sesión
 * del autor (`AuthenticateAuthor`) y la pertenencia, `ResolveAuthorSubmission`.
 */
final class UpdateSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'form_version' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:'.StoreSubmissionRequest::TITLE_MAX],
            'track_id' => ['required', 'uuid'],
            'answers' => ['nullable', 'array'],
            'participants' => ['required', 'array', 'max:20'],
            'participants.*' => ['array'],
            'participants.*.first_name' => ['required', 'string', 'max:100'],
            'participants.*.last_name' => ['required', 'string', 'max:100'],
            'participants.*.country' => ['required', 'string', Rule::in(Countries::CODES)],
            'participants.*.job_title' => ['nullable', 'string', 'max:120'],
            'participants.*.company' => ['nullable', 'string', 'max:160'],
            'participants.*.bio' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
