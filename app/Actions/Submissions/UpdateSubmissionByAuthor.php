<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Forms\BuildSubmissionRules;
use App\Enums\CallState;
use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\CallNotOpen;
use App\Exceptions\Domain\CallNotReady;
use App\Exceptions\Domain\FormOutdated;
use App\Exceptions\Domain\SubmissionNotEditable;
use App\Exceptions\Domain\SubmissionVersionConflict;
use App\Http\Requests\Submissions\StoreSubmissionRequest;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Support\Calls\ResolveCallState;
use App\Support\Submissions\FormSnapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * El autor edita su propuesta (plan 006, H-006-4, CA-006-41 a 45). Exige el contexto de organización.
 *
 * Todo ocurre en una transacción con la fila bloqueada. Solo se edita una propuesta `submitted` de una
 * convocatoria efectivamente abierta (el cierre es exacto, como en el envío). El cliente trae la `version`
 * que vio: si ya no es la vigente, `SubmissionVersionConflict` y no se pisa nada (bloqueo optimista). Las
 * respuestas se validan con `BuildSubmissionRules` y la definición VIGENTE; si el formulario cambió desde que
 * la persona lo abrió, `FormOutdated`. Se actualizan `form_version` y `form_snapshot`.
 *
 * Qué se puede cambiar: título, eje, respuestas (salvo archivos, que no se editan en R0) y los datos de los
 * participantes ya existentes, por posición (sin altas ni bajas). Nunca el correo de ningún participante, el
 * estado, la convocatoria ni la organización (DA-6): lo que el cliente mande de eso se ignora. La auditoría
 * guarda qué cambió (valores anterior y nuevo del título y del eje; solo los nombres de los campos
 * cambiados en lo demás), sin datos personales. El correo `submission.updated` se encola tras confirmar.
 *
 * @throws SubmissionNotEditable
 * @throws SubmissionVersionConflict
 * @throws CallNotOpen
 * @throws CallNotReady
 * @throws FormOutdated
 * @throws ValidationException
 */
final class UpdateSubmissionByAuthor
{
    private const KEYS = ['version', 'form_version', 'title', 'track_id', 'answers', 'participants'];

    private const PARTICIPANT_KEYS = ['first_name', 'last_name', 'country', 'second_last_name', 'alt_email', 'mobile', 'state', 'city', 'phone', 'job_title', 'company', 'bio'];

    public function __construct(
        private readonly LoadPublishedForm $publishedForm,
        private readonly BuildSubmissionRules $answerRules,
        private readonly NotifySubmissionUpdated $notify,
    ) {}

    /** @param  array<string, mixed>  $input  cuerpo de la petición; se ignora todo lo que no sea del contrato */
    public function handle(Submission $submission, array $input): Submission
    {
        $data = $this->normalize($input);

        $updated = DB::transaction(fn (): Submission => $this->update($submission, $data));

        $this->notify->handle($updated);

        return $updated;
    }

    /** @param  array<string, mixed>  $data */
    private function update(Submission $submission, array $data): Submission
    {
        $current = Submission::query()->lockForUpdate()->findOrFail($submission->id);

        if ($current->status !== SubmissionStatus::Submitted || $current->call_for_submissions_id === null) {
            throw new SubmissionNotEditable;
        }

        $call = CallForSubmissions::query()->sharedLock()->findOrFail($current->call_for_submissions_id);

        // El reloj del servidor DENTRO de la transacción: el cierre es exclusivo, sin gracia (CA-006-42).
        if (ResolveCallState::for($call, Date::now()) !== CallState::Open) {
            throw new CallNotOpen;
        }

        if (! is_numeric($data['version'] ?? null)) {
            throw ValidationException::withMessages(['version' => __('validation.required', ['attribute' => 'version'])]);
        }

        if ((int) $data['version'] !== $current->version) {
            throw new SubmissionVersionConflict;
        }

        $form = $this->publishedForm->handle($call);

        if (! is_numeric($data['form_version'] ?? null)) {
            throw ValidationException::withMessages(['form_version' => __('validation.required', ['attribute' => 'form_version'])]);
        }

        if ((int) $data['form_version'] !== $form->version) {
            throw new FormOutdated;
        }

        $participants = SubmissionParticipant::query()->where('submission_id', $current->id)->orderBy('position')->get();
        $answers = $this->validated($call, $form, $data, $participants->count());
        $track = $this->activeTrack($current->event_id, (string) $data['track_id']);

        $before = ['title' => $current->title, 'track_id' => $current->track_id, 'form_version' => $current->form_version, 'answers' => $current->answers];
        $mergedAnswers = $this->mergeAnswers($current->answers, $form, $answers);
        $changedParticipants = $this->updateParticipants($participants->all(), array_values((array) $data['participants']));

        $current->forceFill([
            'title' => $data['title'],
            'track_id' => $track->id,
            'answers' => $mergedAnswers,
            'form_version' => $form->version,
            'form_snapshot' => FormSnapshot::of($form),
            'version' => $current->version + 1,
        ])->save();

        activity()
            ->performedOn($current)
            ->event('submission_updated')
            ->withProperties([
                'organization_id' => $current->organization_id,
                'event_id' => $current->event_id,
                'title' => ['old' => $before['title'], 'new' => $current->title],
                'track_id' => ['old' => $before['track_id'], 'new' => $current->track_id],
                'form_version' => ['old' => $before['form_version'], 'new' => $current->form_version],
                'version' => ['old' => $current->version - 1, 'new' => $current->version],
                'answers_changed' => $this->changedKeys($before['answers'], $mergedAnswers),
                'participants_changed' => $changedParticipants,
            ])
            ->log('submission_updated');

        return $current;
    }

    /**
     * Valida el bloque fijo y las respuestas juntos para devolver todos los errores a la vez.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> respuestas limpias de los campos que no son de archivo
     */
    private function validated(CallForSubmissions $call, FormDefinition $form, array $data, int $participantCount): array
    {
        $base = StoreSubmissionRequest::fieldRules($call->min_participants, $call->max_participants, $call->locales);
        $rules = Arr::only($base, ['form_version', 'title', 'track_id', 'answers', 'participants.*'])
            + Arr::only($base, array_map(static fn (string $key): string => 'participants.*.'.$key, self::PARTICIPANT_KEYS))
            + ['participants' => ['required', 'array', 'size:'.$participantCount]];

        $errors = [];
        $answers = [];
        $fixed = Validator::make($data, $rules);

        if ($fixed->fails()) {
            $errors = $fixed->errors()->toArray();
        }

        try {
            $answers = $this->answerRules->validate($this->withoutFiles($form->fields), (array) ($data['answers'] ?? []), '', $call->organization_id);
        } catch (ValidationException $exception) {
            $errors = array_merge($errors, $exception->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * Los archivos no se editan en R0: su valor guardado se conserva y lo que llegue de ellos se rechaza como
     * clave desconocida.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function withoutFiles(array $fields): array
    {
        return array_values(array_filter($fields, static fn (array $field): bool => ($field['type'] ?? null) !== FieldType::File->value));
    }

    /**
     * Parte de lo guardado y reemplaza solo los campos vigentes que no son de archivo: un opcional que
     * la persona vació se borra; los archivos y los campos retirados conservan su valor.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function mergeAnswers(array $stored, FormDefinition $form, array $validated): array
    {
        foreach ($this->withoutFiles($form->fields) as $field) {
            if (($field['retiredAt'] ?? null) === null) {
                unset($stored[(string) $field['key']]);
            }
        }

        return array_merge($stored, $validated);
    }

    /**
     * @param  list<SubmissionParticipant>  $participants  en orden de posición
     * @param  list<mixed>  $incoming  mismos participantes, mismo orden
     * @return list<int> posiciones cuyos datos cambiaron
     */
    private function updateParticipants(array $participants, array $incoming): array
    {
        $changed = [];

        foreach ($participants as $index => $participant) {
            $values = (array) $incoming[$index];
            $participant->forceFill([
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'full_name' => SubmitSubmission::joinName($values['first_name'], $values['last_name'], $values['second_last_name'] ?? null),
                'country' => $values['country'],
                'second_last_name' => $values['second_last_name'] ?? null,
                'alt_email' => $values['alt_email'] ?? null,
                'mobile' => $values['mobile'] ?? null,
                'state' => $values['state'] ?? null,
                'city' => $values['city'] ?? null,
                'phone' => $values['phone'] ?? null,
                'job_title' => $values['job_title'] ?? null,
                'company' => $values['company'] ?? null,
                'bio' => $values['bio'] ?? null,
            ]);

            if ($participant->isDirty()) {
                $participant->save();
                $changed[] = $participant->position;
            }
        }

        return $changed;
    }

    private function activeTrack(string $eventId, string $trackId): Track
    {
        $track = Track::query()->active()->where('event_id', $eventId)->find($trackId);

        if (! $track instanceof Track) {
            throw ValidationException::withMessages(['track_id' => __('submission_form.track_invalid')]);
        }

        return $track;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string> claves de respuestas distintas (sin los valores)
     */
    private function changedKeys(array $old, array $new): array
    {
        $keys = array_unique([...array_keys($old), ...array_keys($new)]);

        return array_values(array_filter($keys, static fn (string|int $key): bool => ($old[$key] ?? null) !== ($new[$key] ?? null)));
    }

    /**
     * Solo las claves del contrato, con texto recortado y sin caracteres de control. El correo, el estado,
     * la organización y demás no son del contrato y desaparecen aquí (CA-006-45).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $data = array_intersect_key($input, array_flip(self::KEYS));

        foreach (['title', 'track_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::clean($data[$key]);
            }
        }

        if (isset($data['participants']) && is_array($data['participants'])) {
            $data['participants'] = array_map(static function (mixed $participant): mixed {
                if (! is_array($participant)) {
                    return $participant;
                }

                $clean = array_map(self::clean(...), array_intersect_key($participant, array_flip(self::PARTICIPANT_KEYS)));

                foreach (['second_last_name', 'alt_email', 'mobile', 'state', 'city', 'phone', 'job_title', 'company', 'bio'] as $optional) {
                    if (($clean[$optional] ?? null) === '') {
                        $clean[$optional] = null;
                    }
                }

                return $clean;
            }, array_values($data['participants']));
        }

        return $data;
    }

    private static function clean(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '');
    }
}
