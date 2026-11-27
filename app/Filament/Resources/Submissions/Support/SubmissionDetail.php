<?php

declare(strict_types=1);

namespace App\Filament\Resources\Submissions\Support;

use App\Actions\Submissions\DownloadSubmissionFile;
use App\Enums\FieldType;
use App\Enums\SubmissionFileStatus;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Files\SubmissionFileData;
use App\Support\Forms\ProposalFields;
use App\Support\Reviews\BlindReview;
use Illuminate\Support\Number;

/**
 * Datos que muestra el detalle de una propuesta en el panel (plan 009, H-009-2). Las respuestas se
 * leen de la instantánea del formulario con que se enviaron (ADR-0010 §5), no de la definición
 * vigente: un campo retirado después conserva su etiqueta (CA-009-9). Todo es texto plano; la vista
 * lo imprime con `{{ }}` (escapado), nunca como HTML (CA-009-8).
 *
 * Los archivos se enlazan a la ruta de descarga del plan 007 solo para quien tiene `files.download`
 * (CA-009-6, CA-009-10); sin ese permiso solo se informa cuántos hay.
 */
final class SubmissionDetail
{
    /**
     * @return array{
     *     answers: list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int, empty: bool}>,
     *     participants: list<array{photoUrl: ?string, fields: list<array{label: string, value: string}>, name: string, email: string, country: string, state: ?string, city: ?string, phone: ?string, altEmail: ?string, jobTitle: ?string, company: ?string, bio: ?string, primary: bool}>,
     *     canDownload: bool,
     *     blind: bool
     * }
     */
    public static function for(Submission $submission, User $viewer, Organization $organization): array
    {
        return self::build($submission, $organization, $viewer->checkPermissionTo('files.download'), false, false);
    }

    /**
     * El mismo detalle para el evaluador asignado (plan 011, H-011-3): sus archivos se descargan por la ruta
     * del panel del evaluador y con nombre neutro `archivo-{n}.{ext}`, nunca con el original, que puede
     * delatar al autor (plan 007, CA-007-23). El llamador ya comprobó que la propuesta es suya.
     *
     * Con revisión ciega (`BlindReview`, CA-011-18 y 19) el ocultamiento es de ESTE método, en el servidor: no
     * salen los participantes ni los campos con `identifiesAuthor`, así que sus datos no llegan a la vista, a
     * los atributos ni al estado de Livewire. `blind` solo avisa a la vista para explicar por qué faltan.
     *
     * @return array{
     *     answers: list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int, empty: bool}>,
     *     participants: list<array{photoUrl: ?string, fields: list<array{label: string, value: string}>, name: string, email: string, country: string, state: ?string, city: ?string, phone: ?string, altEmail: ?string, jobTitle: ?string, company: ?string, bio: ?string, primary: bool}>,
     *     canDownload: bool,
     *     blind: bool
     * }
     */
    public static function forReviewer(Submission $submission, Organization $organization): array
    {
        return self::build($submission, $organization, true, true, BlindReview::appliesTo($submission));
    }

    /**
     * @return array{
     *     answers: list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int, empty: bool}>,
     *     participants: list<array{photoUrl: ?string, fields: list<array{label: string, value: string}>, name: string, email: string, country: string, state: ?string, city: ?string, phone: ?string, altEmail: ?string, jobTitle: ?string, company: ?string, bio: ?string, primary: bool}>,
     *     canDownload: bool,
     *     blind: bool
     * }
     */
    private static function build(Submission $submission, Organization $organization, bool $canDownload, bool $forReviewer, bool $blind): array
    {
        $filesByField = self::filesByField($submission);

        return [
            'answers' => self::answers($submission, $filesByField, $canDownload, $organization, $forReviewer, $blind),
            // En modo ciego ni siquiera se carga la relación: los autores no pasan por memoria.
            'participants' => $blind ? [] : $submission->participants->map(fn ($participant): array => [
                'photoUrl' => self::photoUrl($participant->photo_file_id, $canDownload, $organization),
                'fields' => self::participantFields($participant),
                'name' => $participant->full_name,
                'email' => $participant->email,
                'country' => $participant->country,
                'state' => $participant->state,
                'city' => $participant->city,
                'phone' => $participant->phone,
                'altEmail' => $participant->alt_email,
                'jobTitle' => $participant->job_title,
                'company' => $participant->company,
                'bio' => $participant->bio,
                'primary' => $participant->is_primary,
            ])->values()->all(),
            'canDownload' => $canDownload,
            'blind' => $blind,
        ];
    }

    /** Imagen de la fotografía del participante en el panel; sin permiso de descarga o sin foto, nada. */
    private static function photoUrl(?string $fileId, bool $canDownload, Organization $organization): ?string
    {
        if ($fileId === null || ! $canDownload) {
            return null;
        }

        $isImage = SubmissionFile::query()
            ->whereKey($fileId)
            ->where('status', SubmissionFileStatus::Attached)
            ->whereIn('mime_type', DownloadSubmissionFile::IMAGE_TYPES)
            ->exists();

        return $isImage ? route('submission-files.preview', ['organization' => $organization->slug, 'file' => $fileId]) : null;
    }

    /**
     * Cada dato del participante con su etiqueta (todo lo que dejó en el formulario); los vacíos no se muestran.
     *
     * @return list<array{label: string, value: string}>
     */
    private static function participantFields(SubmissionParticipant $participant): array
    {
        $values = [
            'second_last_name' => $participant->second_last_name,
            'email' => $participant->email,
            'alt_email' => $participant->alt_email,
            'phone' => $participant->phone,
            'company' => $participant->company,
            'job_title' => $participant->job_title,
            'country' => $participant->country,
            'state' => $participant->state,
            'city' => $participant->city,
            'bio' => $participant->bio,
        ];

        $rows = [];

        foreach ($values as $key => $value) {
            if (is_string($value) && $value !== '') {
                $rows[] = ['label' => __("submission_panel.detail.participant.{$key}"), 'value' => $value];
            }
        }

        return $rows;
    }

    /** @return array<string, list<SubmissionFile>> archivos ya ligados a la propuesta, por clave de campo */
    private static function filesByField(Submission $submission): array
    {
        return SubmissionFile::query()
            ->where('submission_id', $submission->id)
            ->where('status', SubmissionFileStatus::Attached)
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->get()
            ->groupBy('form_field_key')
            ->map(static fn ($files) => $files->all())
            ->all();
    }

    /**
     * @param  array<string, list<SubmissionFile>>  $filesByField
     * @return list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int, empty: bool}>
     */
    private static function answers(Submission $submission, array $filesByField, bool $canDownload, Organization $organization, bool $forReviewer, bool $blind): array
    {
        // Posición de cada archivo en la propuesta, para su nombre neutro (solo el evaluador).
        $positions = $forReviewer ? SubmissionFileData::positions($submission->id) : null;
        $hidden = $blind ? BlindReview::identifyingFieldKeys($submission) : [];
        $fallback = $submission->locale;
        $locale = app()->getLocale();
        $fields = is_array($submission->form_snapshot['fields'] ?? null) ? $submission->form_snapshot['fields'] : [];
        $rows = [];
        $seen = [];

        foreach ($fields as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            $type = is_array($field) ? FieldType::tryFrom((string) ($field['type'] ?? '')) : null;

            if (! is_string($key) || $type === null) {
                continue;
            }

            $seen[$key] = true;

            // Revisión ciega: el campo que identifica al autor no sale, con su valor ni con sus archivos.
            if (in_array($key, $hidden, true)) {
                continue;
            }

            $files = $type === FieldType::File ? ($filesByField[$key] ?? []) : [];

            $answered = array_key_exists($key, $submission->answers) || $files !== [];

            // El título y el tema van en el encabezado de la propuesta, no entre las respuestas.
            if (! $answered && ProposalFields::isRoleField($field)) {
                continue;
            }

            $value = $type === FieldType::File ? null : self::value($type, $field, $submission->answers[$key] ?? null, $locale, $fallback);

            $rows[] = [
                'label' => self::text($field['label'] ?? null, $locale, $fallback) ?: $key,
                'retired' => false,
                'type' => $type->value,
                'value' => $value,
                'files' => self::files($files, $canDownload, $organization, $positions),
                'fileCount' => count($files),
                // Pregunta que quedó sin responder: el detalle la muestra como «Sin respuesta».
                'empty' => $type === FieldType::File
                    ? $files === []
                    : (! $answered || $value === null || $value === '' || $value === []),
            ];
        }

        // Respuesta cuya clave no está en la instantánea (no debería ocurrir): se muestra, no se pierde. Salvo en
        // revisión ciega: sin su definición no se sabe si delata al autor, así que no sale.
        foreach ($submission->answers as $key => $answer) {
            if (isset($seen[$key]) || $blind) {
                continue;
            }

            $rows[] = [
                'label' => (string) $key,
                'retired' => true,
                'type' => FieldType::ShortText->value,
                'value' => is_scalar($answer) ? (string) $answer : json_encode($answer, JSON_UNESCAPED_UNICODE),
                'files' => [],
                'fileCount' => 0,
                'empty' => false,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<SubmissionFile>  $files
     * @param  array<string, int>|null  $positions  posición de cada archivo en la propuesta, desde 1 (id => posición), si el detalle es del evaluador
     * @return list<array{name: string, size: string, url: string|null, previewUrl: string|null}>
     */
    private static function files(array $files, bool $canDownload, Organization $organization, ?array $positions): array
    {
        if (! $canDownload) {
            return [];
        }

        return array_map(static fn (SubmissionFile $file): array => [
            'name' => $positions === null
                ? $file->original_name
                : SubmissionFileData::forReviewer($file, $positions[$file->id] ?? 1)['name'],
            'size' => Number::fileSize($file->size_bytes),
            'url' => route($positions === null ? 'submission-files.download' : 'reviewer-submission-files.download', ['organization' => $organization->slug, 'file' => $file->id]),
            // Las imágenes (JPG, PNG) también se muestran dentro de la página; el evaluador las descarga.
            'previewUrl' => $positions === null && in_array($file->mime_type, DownloadSubmissionFile::IMAGE_TYPES, true)
                ? route('submission-files.preview', ['organization' => $organization->slug, 'file' => $file->id])
                : null,
        ], $files);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|bool|list<string>|null
     */
    private static function value(FieldType $type, array $field, mixed $answer, string $locale, string $fallback): string|bool|array|null
    {
        return match ($type) {
            FieldType::Checkbox => (bool) $answer,
            FieldType::SingleChoice => is_string($answer) ? self::optionLabel($field, $answer, $locale, $fallback) : null,
            FieldType::MultipleChoice => is_array($answer)
                ? array_values(array_map(fn (mixed $value): string => self::optionLabel($field, (string) $value, $locale, $fallback), $answer))
                : [],
            default => is_scalar($answer) ? (string) $answer : null,
        };
    }

    /** @param  array<string, mixed>  $field */
    private static function optionLabel(array $field, string $value, string $locale, string $fallback): string
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return self::text($option['label'] ?? null, $locale, $fallback) ?: $value;
            }
        }

        return $value;
    }

    /** Texto por idioma con respaldo en el de la propuesta y, al final, en el primero que exista. */
    private static function text(mixed $localized, string $locale, string $fallback): string
    {
        if (! is_array($localized)) {
            return '';
        }

        foreach ([$locale, $fallback] as $candidate) {
            if (is_string($localized[$candidate] ?? null) && $localized[$candidate] !== '') {
                return $localized[$candidate];
            }
        }

        foreach ($localized as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }
}
