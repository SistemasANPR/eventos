<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\CallState;
use App\Enums\FieldType;
use App\Enums\FormDefinitionStatus;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Submission;
use App\Models\Track;
use App\Support\Calls\ResolveCallState;
use App\Support\Forms\FormPreviewProps;

/**
 * Datos de la pantalla «editar mi propuesta» (plan 006, H-006-4): el formulario VIGENTE sin campos de archivo
 * (no se editan en R0), los ejes activos y los valores actuales de la propuesta. Devuelve `null` si la
 * propuesta no se puede editar ahora (no está `submitted`, la convocatoria no está abierta o el formulario
 * no está listo). Es solo una guía para la pantalla: `UpdateSubmissionByAuthor` vuelve a comprobarlo todo.
 * Requiere el contexto de organización.
 */
final class PrepareAuthorEdit
{
    private const FIXED = 's.';

    public function __construct(private readonly FormPreviewProps $fields) {}

    /** ¿La propuesta admite edición ahora mismo? (estado y convocatoria abierta). */
    public function isEditable(Submission $submission): bool
    {
        return $this->openCall($submission) instanceof CallForSubmissions;
    }

    /** @return array<string, mixed>|null */
    public function handle(Submission $submission, Event $event, string $locale): ?array
    {
        $call = $this->openCall($submission);
        $form = $call?->form_definition_id === null ? null : FormDefinition::query()->find($call->form_definition_id);

        if (! $form instanceof FormDefinition || $form->status !== FormDefinitionStatus::Published || $form->event_id !== $submission->event_id) {
            return null;
        }

        $renderer = $this->fields->handle($form, $event, $locale);
        $editable = array_values(array_filter($renderer['fields'], static fn (array $field): bool => $field['type'] !== FieldType::File->value));

        return [
            'version' => $submission->version,
            'formVersion' => $form->version,
            'fields' => $editable,
            'defaultLocale' => $renderer['defaultLocale'],
            'translations' => $renderer['translations'],
            'tracks' => $event->tracks()->active()->orderBy('position')->get()
                ->map(fn (Track $track): array => ['id' => $track->id, 'name' => $track->label($locale)])->values()->all(),
            'initialValues' => $this->initialValues($submission, $editable),
        ];
    }

    private function openCall(Submission $submission): ?CallForSubmissions
    {
        if ($submission->status !== SubmissionStatus::Submitted || $submission->call_for_submissions_id === null) {
            return null;
        }

        $call = CallForSubmissions::query()->find($submission->call_for_submissions_id);

        return $call instanceof CallForSubmissions && ResolveCallState::for($call) === CallState::Open ? $call : null;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, string|bool|list<string>>
     */
    private function initialValues(Submission $submission, array $fields): array
    {
        $values = [self::FIXED.'title' => $submission->title, self::FIXED.'track_id' => $submission->track_id];

        foreach ($submission->participants as $index => $participant) {
            $prefix = self::FIXED.'participants.'.$index.'.';
            // Un participante anterior al plan 021 puede no tener nombre y apellido separados.
            $values[$prefix.'first_name'] = $participant->first_name ?? $participant->full_name;
            $values[$prefix.'last_name'] = $participant->last_name ?? '';
            $values[$prefix.'country'] = $participant->country;
            $values[$prefix.'job_title'] = (string) $participant->job_title;
            $values[$prefix.'company'] = (string) $participant->company;
            $values[$prefix.'bio'] = (string) $participant->bio;
        }

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $answer = $submission->answers[$key] ?? null;

            if ($answer === null) {
                continue;
            }

            $values[$key] = match ($field['type']) {
                FieldType::Checkbox->value => (bool) $answer,
                FieldType::MultipleChoice->value => array_values(array_map('strval', (array) $answer)),
                default => is_scalar($answer) ? (string) $answer : '',
            };
        }

        return $values;
    }
}
