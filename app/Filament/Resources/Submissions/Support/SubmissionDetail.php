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
     *     answers: list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int}>,
     *     participants: list<array{photoUrl: ?string, fields: list<array{label: string, value: string}>, name: string, email: string, country: string, state: ?string, city: ?string, phone: ?string, mobile: ?string, altEmail: ?string, jobTitle: ?string, company: ?string, bio: ?string, primary: bool}>,
     *     canDownload: bool
     * }
     */
    public static function for(Submission $submission, User $viewer, Organization $organization): array
    {
        $canDownload = $viewer->checkPermissionTo('files.download');
        $filesByField = self::filesByField($submission);

        return [
            'answers' => self::answers($submission, $filesByField, $canDownload, $organization),
            'participants' => $submission->participants->map(fn ($participant): array => [
                'photoUrl' => self::photoUrl($participant->photo_file_id, $canDownload, $organization),
                'fields' => self::participantFields($participant),
                'name' => $participant->full_name,
                'email' => $participant->email,
                'country' => $participant->country,
                'state' => $participant->state,
                'city' => $participant->city,
                'phone' => $participant->phone,
                'mobile' => $participant->mobile,
                'altEmail' => $participant->alt_email,
                'jobTitle' => $participant->job_title,
                'company' => $participant->company,
                'bio' => $participant->bio,
                'primary' => $participant->is_primary,
            ])->values()->all(),
            'canDownload' => $canDownload,
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
            'email' => $participant->email,
            'alt_email' => $participant->alt_email,
            'mobile' => $participant->mobile,
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
            ->get()
            ->groupBy('form_field_key')
            ->map(static fn ($files) => $files->all())
            ->all();
    }

    /**
     * @param  array<string, list<SubmissionFile>>  $filesByField
     * @return list<array{label: string, retired: bool, type: string, value: string|bool|list<string>|null, files: list<array{name: string, size: string, url: string|null, previewUrl: string|null}>, fileCount: int}>
     */
    private static function answers(Submission $submission, array $filesByField, bool $canDownload, Organization $organization): array
    {
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
            $files = $type === FieldType::File ? ($filesByField[$key] ?? []) : [];

            // Un campo sin respuesta ni archivos no aporta nada al detalle.
            if (! array_key_exists($key, $submission->answers) && $files === []) {
                continue;
            }

            $rows[] = [
                'label' => self::text($field['label'] ?? null, $locale, $fallback) ?: $key,
                'retired' => false,
                'type' => $type->value,
                'value' => $type === FieldType::File ? null : self::value($type, $field, $submission->answers[$key] ?? null, $locale, $fallback),
                'files' => self::files($files, $canDownload, $organization),
                'fileCount' => count($files),
            ];
        }

        // Respuesta cuya clave no está en la instantánea (no debería ocurrir): se muestra, no se pierde.
        foreach ($submission->answers as $key => $answer) {
            if (isset($seen[$key])) {
                continue;
            }

            $rows[] = [
                'label' => (string) $key,
                'retired' => true,
                'type' => FieldType::ShortText->value,
                'value' => is_scalar($answer) ? (string) $answer : json_encode($answer, JSON_UNESCAPED_UNICODE),
                'files' => [],
                'fileCount' => 0,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<SubmissionFile>  $files
     * @return list<array{name: string, size: string, url: string|null, previewUrl: string|null}>
     */
    private static function files(array $files, bool $canDownload, Organization $organization): array
    {
        if (! $canDownload) {
            return [];
        }

        return array_map(static fn (SubmissionFile $file): array => [
            'name' => $file->original_name,
            'size' => Number::fileSize($file->size_bytes),
            'url' => route('submission-files.download', ['organization' => $organization->slug, 'file' => $file->id]),
            // Las imágenes (JPG, PNG) también se muestran dentro de la página.
            'previewUrl' => in_array($file->mime_type, DownloadSubmissionFile::IMAGE_TYPES, true)
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
