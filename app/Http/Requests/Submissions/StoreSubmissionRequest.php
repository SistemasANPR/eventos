<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Actions\Forms\BuildSubmissionRules;
use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Support\Calls\ResolveCallState;
use App\Support\Forms\Countries;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

    /**
     * Formulario público: no hay usuario que autorizar; la protección es el límite de peticiones y el
     * anti-robots. Sí se cierra el paso a lo que el público no ve: un evento sin publicar o una
     * convocatoria en borrador responden 404 antes de validar nada (CA-006-2).
     */
    public function authorize(): bool
    {
        $event = $this->route('event');
        $call = $this->route('call');

        if (! $event instanceof Event || ! $event->isPublished()
            || ! $call instanceof CallForSubmissions || ResolveCallState::for($call) === null) {
            throw new NotFoundHttpException;
        }

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $call = $this->route('call');
        $fixed = $call instanceof CallForSubmissions
            ? self::fieldRules($call->min_participants, $call->max_participants, $call->locales)
            : self::fieldRules();

        return $fixed + [
            // Los verifican `VerifyTurnstile` y el almacén de cargas; aquí solo se acota su forma.
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
            'upload_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return list<Closure> */
    public function after(): array
    {
        return [
            static fn (Validator $validator) => self::crossFieldChecks($validator),
            fn (Validator $validator) => $this->checkAnswers($validator),
        ];
    }

    /** Correo del participante principal: con él se cuentan los acuses por correo (CA-006-26). */
    public function primaryEmail(): string
    {
        foreach ((array) $this->validated('participants') as $participant) {
            if (is_array($participant) && filter_var($participant['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return (string) ($participant['email'] ?? '');
            }
        }

        return '';
    }

    /**
     * Valida también las respuestas del formulario para devolver todos los errores a la vez y no en
     * dos rondas. Es una comprobación previa y de solo lectura: la autoritativa es la de
     * `SubmitSubmission`, que vuelve a validar con el formulario bloqueado.
     */
    private function checkAnswers(Validator $validator): void
    {
        $call = $this->route('call');

        if (! $call instanceof CallForSubmissions || $call->form_definition_id === null) {
            return;
        }

        $form = FormDefinition::query()->find($call->form_definition_id);

        if (! $form instanceof FormDefinition || $form->status !== FormDefinitionStatus::Published) {
            return;
        }

        $answers = $validator->getData()['answers'] ?? [];

        try {
            app(BuildSubmissionRules::class)->validate(
                $form->fields,
                is_array($answers) ? $answers : [],
                (string) ($validator->getData()['upload_token'] ?? ''),
                $call->organization_id,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }
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
            'participants.*.full_name' => ['required', 'string', 'max:120'],
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
