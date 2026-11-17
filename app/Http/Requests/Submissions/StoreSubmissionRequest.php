<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Models\CallForSubmissions;
use App\Support\Forms\Countries;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Envío público de una propuesta (plan 006, H-006-1). Valida el bloque fijo (título, eje,
 * participantes), el consentimiento y la clave de idempotencia; las respuestas dinámicas las
 * valida `BuildSubmissionRules` con la definición vigente del formulario, no este Request.
 *
 * Las reglas viven en métodos estáticos para que las use también `SubmitSubmission`, que no
 * tiene petición HTTP. La organización, el evento, la convocatoria y el estado NO se leen
 * de la entrada (CA-006-7): salen de la ruta.
 */
final class StoreSubmissionRequest extends FormRequest
{
    public const TITLE_MAX = 200;

    public function authorize(): bool
    {
        // Formulario público: la protección es el límite de peticiones y el anti-robots (F3).
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $call = $this->route('call');

        return $call instanceof CallForSubmissions
            ? self::fieldRules($call->min_participants, $call->max_participants, $call->locales)
            : self::fieldRules();
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [static fn (Validator $validator) => self::crossFieldChecks($validator)];
    }

    /**
     * @param  list<string>  $locales  idiomas de la convocatoria
     * @return array<string, list<mixed>>
     */
    public static function fieldRules(int $minParticipants = 1, int $maxParticipants = 5, array $locales = ['es', 'en', 'pt']): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'form_version' => ['required', 'integer', 'min:1'],
            'locale' => ['required', 'string', Rule::in($locales)],
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX],
            'track_id' => ['required', 'uuid'],
            'answers' => ['nullable', 'array'],
            'participants' => ['required', 'array', 'min:'.$minParticipants, 'max:'.$maxParticipants],
            'participants.*' => ['array'],
            // `full_name` lo calcula el servidor (plan 021, DA-9); sin controles ni saltos de línea.
            'participants.*.first_name' => ['required', 'string', 'max:100', 'regex:/^[^\p{Cc}]+$/u'],
            'participants.*.last_name' => ['required', 'string', 'max:100', 'regex:/^[^\p{Cc}]+$/u'],
            'participants.*.email' => ['required', 'string', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'participants.*.country' => ['required', 'string', Rule::in(Countries::CODES)],
            'participants.*.job_title' => ['nullable', 'string', 'max:120'],
            'participants.*.company' => ['nullable', 'string', 'max:160'],
            'participants.*.bio' => ['nullable', 'string', 'max:2000'],
            'participants.*.is_primary' => ['nullable', 'boolean'],
            'consent' => ['accepted'],
            'privacy_notice_version_id' => ['nullable', 'uuid'],
            'third_parties_authorized' => ['nullable', 'boolean'],
        ];
    }

    /** Reglas que dependen de varios campos: un solo principal y la declaración de terceros (CA-006-5, CA-006-54). */
    public static function crossFieldChecks(Validator $validator): void
    {
        $participants = $validator->getData()['participants'] ?? null;

        if (! is_array($participants) || $participants === []) {
            return;
        }

        $primaries = array_filter($participants, static fn (mixed $participant): bool => is_array($participant)
            && filter_var($participant['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN));

        if (count($primaries) !== 1) {
            $validator->errors()->add('participants', __('submission_form.participants_primary'));
        }

        $declared = filter_var($validator->getData()['third_parties_authorized'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (count($participants) > 1 && ! $declared) {
            $validator->errors()->add('third_parties_authorized', __('submission_form.participants_third_parties'));
        }
    }
}
