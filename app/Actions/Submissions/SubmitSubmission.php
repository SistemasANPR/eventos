<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Forms\BuildSubmissionRules;
use App\Enums\CallState;
use App\Enums\FieldType;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\CallNotOpen;
use App\Exceptions\Domain\CallNotReady;
use App\Exceptions\Domain\FormOutdated;
use App\Exceptions\Domain\IdempotencyPayloadMismatch;
use App\Http\Requests\Submissions\StoreSubmissionRequest;
use App\Models\CallForSubmissions;
use App\Models\Contact;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Models\Track;
use App\Support\Calls\ResolveCallState;
use App\Support\Forms\IdempotencyKeys;
use App\Support\Submissions\FormSnapshot;
use App\Support\Submissions\SubmitOutcome;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Registra el envío público de una propuesta (plan 006, H-006-1). Es el único camino de
 * escritura: la organización, el evento, la convocatoria, el estado y la fecha salen del
 * servidor; del cliente solo se leen las claves de `StoreSubmissionRequest` (CA-006-7).
 * Exige el contexto de organización (`CurrentOrganization`).
 *
 * Todo ocurre en una transacción: el formulario se carga con `LockedFormDefinition` (una edición
 * simultánea espera), la convocatoria se comprueba con el reloj del servidor DENTRO de la
 * transacción (el cierre es exclusivo, sin gracia), y cualquier fallo revierte propuesta,
 * participantes y archivos adjuntos (CA-006-9).
 *
 * Idempotencia (CA-006-14 a 16): la clave debe haberla emitido `IssueFormSession` a esta sesión.
 * Repetir clave y carga devuelve la MISMA propuesta (`created = false`); otra carga lanza
 * `IdempotencyPayloadMismatch`. Si dos peticiones compiten, la que pierde la unicidad
 * `(organization_id, idempotency_key)` resuelve a la propuesta ganadora, nunca a un 500.
 *
 * Un participante se vincula a un `Contact` por correo normalizado y NUNCA lo modifica (DA-2).
 * Confirmada la transacción, `NotifySubmissionReceived` encola el acuse y el aviso a organizadores.
 *
 * @throws ModelNotFoundException si la convocatoria es un borrador
 * @throws CallNotOpen si la convocatoria no está abierta
 * @throws CallNotReady si falta formulario publicado o aviso de privacidad en el idioma del envío
 * @throws FormOutdated si `form_version` no es la vigente
 * @throws IdempotencyPayloadMismatch si la clave ya se usó con otra carga
 * @throws ValidationException si la entrada no es válida o la clave no la emitió el servidor
 */
final class SubmitSubmission
{
    private const FIXED_KEYS = [
        'idempotency_key', 'form_version', 'locale', 'title', 'track_id', 'answers', 'participants',
        'consent', 'privacy_notice_version_id', 'third_parties_authorized',
    ];

    /** `full_name` no es del contrato: lo calcula el servidor a partir de nombre y apellido. */
    private const PARTICIPANT_KEYS = ['first_name', 'last_name', 'email', 'country', 'job_title', 'company', 'bio', 'is_primary'];

    public function __construct(
        private readonly LoadPublishedForm $publishedForm,
        private readonly BuildSubmissionRules $answerRules,
        private readonly AttachSubmissionFile $attachFiles,
        private readonly IdempotencyKeys $idempotencyKeys,
        private readonly NotifySubmissionReceived $notifyReceived,
    ) {}

    /**
     * @param  array<string, mixed>  $input  cuerpo de la petición; se ignora todo lo que no sea del contrato
     */
    public function handle(CallForSubmissions $call, array $input, string $uploadToken = ''): SubmitOutcome
    {
        $outcome = $this->submit($call, $input, $uploadToken);

        // Los correos se encolan DESPUÉS de confirmar la transacción (CA-006-29) y solo la primera vez:
        // una repetición con la misma clave devuelve la misma propuesta sin repetir efectos (CA-006-14).
        if ($outcome->created) {
            $this->notifyReceived->handle($outcome->submission);
        }

        return $outcome;
    }

    /** @param  array<string, mixed>  $input */
    private function submit(CallForSubmissions $call, array $input, string $uploadToken): SubmitOutcome
    {
        $data = $this->normalize($input);
        $key = is_string($data['idempotency_key'] ?? null) ? $data['idempotency_key'] : '';

        if (! $this->idempotencyKeys->has($call, $key)) {
            throw ValidationException::withMessages(['idempotency_key' => __('submission_form.idempotency_not_issued')]);
        }

        $hash = self::payloadHash($call, $data);
        $existing = $this->findByKey($key);

        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }

        try {
            return DB::transaction(fn (): SubmitOutcome => $this->create($call, $data, $hash, $uploadToken));
        } catch (UniqueConstraintViolationException $exception) {
            // Otra petición con la misma clave ganó la carrera: se resuelve a su propuesta.
            $winner = $this->findByKey($key);

            if ($winner === null) {
                throw $exception;
            }

            return $this->replay($winner, $hash);
        }
    }

    /**
     * Huella de la carga: no depende del orden de las claves ni de la clave de idempotencia.
     *
     * @param  array<string, mixed>  $data  entrada ya normalizada
     */
    public static function payloadHash(CallForSubmissions $call, array $data): string
    {
        unset($data['idempotency_key']);
        $data['call_id'] = $call->id;

        return hash('sha256', (string) json_encode(self::canonical($data)));
    }

    private function findByKey(string $key): ?Submission
    {
        return Submission::query()->withTrashed()->where('idempotency_key', $key)->first();
    }

    private function replay(Submission $existing, string $hash): SubmitOutcome
    {
        if (! is_string($existing->payload_hash) || ! hash_equals($existing->payload_hash, $hash)) {
            throw new IdempotencyPayloadMismatch;
        }

        return new SubmitOutcome($existing, false);
    }

    /** @param  array<string, mixed>  $data */
    private function create(CallForSubmissions $call, array $data, string $hash, string $uploadToken): SubmitOutcome
    {
        // El estado de la convocatoria se lee de nuevo dentro de la transacción: un cambio
        // concurrente (pausa, cierre de emergencia) no puede colarse entre la lectura y el guardado.
        $current = CallForSubmissions::query()->sharedLock()->findOrFail($call->id);
        $event = Event::query()->findOrFail($current->event_id);
        $state = ResolveCallState::for($current, Date::now());

        if ($state === null) {
            throw (new ModelNotFoundException)->setModel(CallForSubmissions::class, [$call->id]);
        }

        if ($state !== CallState::Open) {
            throw new CallNotOpen;
        }

        $form = $this->publishedForm->handle($current);

        if (! is_numeric($data['form_version'] ?? null)) {
            throw ValidationException::withMessages(['form_version' => __('validation.required', ['attribute' => 'form_version'])]);
        }

        if ((int) $data['form_version'] !== $form->version) {
            throw new FormOutdated;
        }

        $answers = $this->validated($current, $form, $data, $uploadToken);
        $track = $this->activeTrack($event, (string) $data['track_id']);
        $notice = $this->currentNotice($event, (string) $data['locale'], $data['privacy_notice_version_id'] ?? null);

        $now = Date::now();
        $participants = array_values((array) $data['participants']);

        $submission = new Submission;
        $submission->forceFill([
            'event_id' => $event->id,
            'origin' => SubmissionOrigin::Call,
            'call_for_submissions_id' => $current->id,
            'submission_type_id' => $current->submission_type_id,
            'form_definition_id' => $form->id,
            'form_version' => $form->version,
            'form_snapshot' => FormSnapshot::of($form),
            'track_id' => $track->id,
            'title' => $data['title'],
            'answers' => $answers,
            'status' => SubmissionStatus::Submitted,
            'locale' => $data['locale'],
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $hash,
            'privacy_notice_version_id' => $notice->id,
            'consented_at' => $now,
            'third_parties_authorized_at' => count($participants) > 1 ? $now : null,
            'submitted_at' => $now,
            'version' => 1,
        ])->save();

        foreach ($participants as $position => $participant) {
            $this->addParticipant($submission, $position, (array) $participant);
        }

        $this->attachUploads($current, $submission, $form, $answers, $uploadToken);

        // Sin datos personales: solo identificadores y conteos.
        activity()
            ->performedOn($submission)
            ->event('submission_submitted')
            ->withProperties([
                'organization_id' => $submission->organization_id,
                'event_id' => $event->id,
                'call_id' => $current->id,
                'form_version' => $form->version,
                'participants' => count($participants),
                'privacy_notice_version_id' => $notice->id,
            ])
            ->log('submission_submitted');

        return new SubmitOutcome($submission, true);
    }

    /**
     * Valida el bloque fijo y las respuestas juntos para devolver todos los errores a la vez.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> respuestas limpias
     */
    private function validated(CallForSubmissions $call, FormDefinition $form, array $data, string $uploadToken): array
    {
        $errors = [];
        $answers = [];

        $fixed = Validator::make($data, StoreSubmissionRequest::fieldRules($call->min_participants, $call->max_participants, $call->locales));
        $fixed->after(static fn ($validator) => StoreSubmissionRequest::crossFieldChecks($validator));

        if ($fixed->fails()) {
            $errors = $fixed->errors()->toArray();
        }

        try {
            $answers = $this->answerRules->validate($form->fields, (array) ($data['answers'] ?? []), $uploadToken, $call->organization_id);
        } catch (ValidationException $exception) {
            $errors = array_merge($errors, $exception->errors());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    private function activeTrack(Event $event, string $trackId): Track
    {
        $track = Track::query()->active()->where('event_id', $event->id)->find($trackId);

        if (! $track instanceof Track) {
            throw ValidationException::withMessages(['track_id' => __('submission_form.track_invalid')]);
        }

        return $track;
    }

    /** El aviso vigente del evento, con texto en el idioma del envío; si el cliente llevó otra versión, se rechaza (CA-006-52). */
    private function currentNotice(Event $event, string $locale, mixed $claimedId): PrivacyNotice
    {
        $notice = PrivacyNotice::currentPublished($event);

        if ($notice === null || trim((string) $notice->getTranslation('body', $locale, false)) === '') {
            throw new CallNotReady;
        }

        if (is_string($claimedId) && $claimedId !== '' && $claimedId !== $notice->id) {
            throw ValidationException::withMessages(['privacy_notice_version_id' => __('submission_form.privacy_notice_outdated')]);
        }

        return $notice;
    }

    /** @param  array<string, mixed>  $participant */
    private function addParticipant(Submission $submission, int $position, array $participant): void
    {
        $contact = $this->contactFor($participant);

        (new SubmissionParticipant)->forceFill([
            'submission_id' => $submission->id,
            'contact_id' => $contact->id,
            'position' => $position + 1,
            'is_primary' => filter_var($participant['is_primary'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'first_name' => $participant['first_name'],
            'last_name' => $participant['last_name'],
            'full_name' => self::fullName($participant),
            'email' => $participant['email'],
            'country' => $participant['country'],
            'job_title' => $participant['job_title'] ?? null,
            'company' => $participant['company'] ?? null,
            'bio' => $participant['bio'] ?? null,
        ])->save();
    }

    /**
     * Reutiliza el `Contact` de la organización con ese correo SIN tocar sus datos; si no existe lo
     * crea. Dos envíos simultáneos con el mismo correo: el que pierde la unicidad relee el ganador.
     *
     * @param  array<string, mixed>  $participant
     */
    private function contactFor(array $participant): Contact
    {
        $email = (string) $participant['email'];

        $found = $this->findContact($email);

        if ($found !== null) {
            return $found;
        }

        try {
            return DB::transaction(fn (): Contact => Contact::query()->create([
                'full_name' => self::fullName($participant),
                'email' => $email,
                'country' => $participant['country'],
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->findContact($email) ?? throw $exception;
        }
    }

    /** @param  array<string, mixed>  $participant */
    private static function fullName(array $participant): string
    {
        return $participant['first_name'].' '.$participant['last_name'];
    }

    private function findContact(string $email): ?Contact
    {
        return Contact::query()->whereRaw('lower(email) = ?', [$email])->first();
    }

    /**
     * Liga las cargas pendientes de cada campo de archivo; cualquier fallo revierte todo.
     *
     * @param  array<string, mixed>  $answers
     */
    private function attachUploads(CallForSubmissions $call, Submission $submission, FormDefinition $form, array $answers, string $uploadToken): void
    {
        foreach ($form->fields as $field) {
            $key = (string) ($field['key'] ?? '');

            if (($field['type'] ?? null) !== FieldType::File->value || ! is_array($answers[$key] ?? null)) {
                continue;
            }

            $ids = array_values(array_filter($answers[$key], 'is_string'));

            if ($ids !== []) {
                $this->attachFiles->handle($call, $submission->id, $uploadToken, $key, $ids);
            }
        }
    }

    /**
     * Solo las claves del contrato, con texto recortado y sin caracteres de control; el correo en
     * minúsculas. Lo que no es texto se deja tal cual para que la validación lo rechace.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $data = array_intersect_key($input, array_flip(self::FIXED_KEYS));

        foreach (['idempotency_key', 'locale', 'title', 'track_id', 'privacy_notice_version_id'] as $key) {
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

                if (is_string($clean['email'] ?? null)) {
                    $clean['email'] = mb_strtolower($clean['email']);
                }

                foreach (['job_title', 'company', 'bio'] as $optional) {
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

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
