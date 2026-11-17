<?php

declare(strict_types=1);

namespace App\Support\SubmissionExport;

use App\Enums\FieldType;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use Illuminate\Support\Collection;

/**
 * Arma los encabezados y las filas de la exportación (plan 009, DA-2, docs/privacidad.md).
 *
 * Columnas fijas: identificador, convocatoria, origen, tipo, eje, título, estado, fecha de envío en la
 * zona del evento, autor y correo; más UNA columna por campo del formulario, con la etiqueta de la
 * instantánea más reciente que lo contiene (ADR-0010: un campo retirado con respuestas conserva su
 * etiqueta). Datos personales: solo nombre y correo del autor principal (minimización); nunca teléfono,
 * biografía, cargo ni empresa. Nunca credenciales, tokens ni URL: los archivos salen como cantidad.
 *
 * TODA celda pasa por `FormulaGuard`, encabezados incluidos.
 */
final class SubmissionExportRows
{
    /**
     * @param  Collection<int, Submission>  $submissions  con `call`, `submissionType`, `track` y `participants` cargados
     * @return array{headings: list<string>, rows: list<list<string>>}
     */
    public static function build(Collection $submissions, Event $event): array
    {
        $locale = $event->default_locale;
        $columns = self::formColumns($submissions, $locale);

        $headings = [
            __('submission_export.columns.id', locale: $locale),
            __('submission_export.columns.call', locale: $locale),
            __('submission_export.columns.origin', locale: $locale),
            __('submission_export.columns.type', locale: $locale),
            __('submission_export.columns.track', locale: $locale),
            __('submission_export.columns.title', locale: $locale),
            __('submission_export.columns.status', locale: $locale),
            __('submission_export.columns.submitted_at', locale: $locale),
            __('submission_export.columns.author', locale: $locale),
            __('submission_export.columns.email', locale: $locale),
            ...array_map(static fn (array $column): string => $column['label'], array_values($columns)),
        ];

        $rows = $submissions->map(static function (Submission $submission) use ($columns, $event, $locale): array {
            $author = self::author($submission);

            return array_map(FormulaGuard::neutralize(...), [
                $submission->id,
                (string) $submission->call?->getTranslation('title', $locale, true),
                $submission->origin->value,
                $submission->submissionType->label($locale),
                (string) $submission->track->getTranslation('name', $locale, true),
                $submission->title,
                $submission->status->value,
                $submission->submitted_at->setTimezone($event->timezone)->format('Y-m-d H:i:s'),
                $author->full_name ?? '',
                $author->email ?? '',
                ...array_map(
                    static fn (array $column): string => self::cell($column, $submission->answers[$column['key']] ?? null, $locale),
                    array_values($columns),
                ),
            ]);
        })->values()->all();

        return [
            'headings' => array_map(FormulaGuard::neutralize(...), $headings),
            'rows' => $rows,
        ];
    }

    /**
     * Una columna por clave de campo, en el orden en que aparecen; la etiqueta sale de la instantánea
     * más reciente que contiene el campo.
     *
     * @param  Collection<int, Submission>  $submissions
     * @return array<string, array{key: string, label: string, type: string, options: array<string, string>}>
     */
    private static function formColumns(Collection $submissions, string $locale): array
    {
        $columns = [];

        foreach ($submissions->sortBy('submitted_at') as $submission) {
            $fields = is_array($submission->form_snapshot['fields'] ?? null) ? $submission->form_snapshot['fields'] : [];

            foreach ($fields as $field) {
                $key = is_array($field) ? ($field['key'] ?? null) : null;
                $type = is_array($field) ? FieldType::tryFrom((string) ($field['type'] ?? '')) : null;

                if (! is_string($key) || $type === null) {
                    continue;
                }

                // Reasignar conserva la posición de la primera aparición y deja la etiqueta más reciente.
                $columns[$key] = [
                    'key' => $key,
                    'label' => self::text($field['label'] ?? null, $locale) ?: $key,
                    'type' => $type->value,
                    'options' => self::options($field, $locale),
                ];
            }
        }

        // Respuestas cuya clave no está en ninguna instantánea: no se pierden, salen con su clave como etiqueta.
        foreach ($submissions as $submission) {
            foreach (array_keys($submission->answers) as $key) {
                $columns[(string) $key] ??= ['key' => (string) $key, 'label' => (string) $key, 'type' => FieldType::ShortText->value, 'options' => []];
            }
        }

        return $columns;
    }

    /**
     * @param  array{key: string, label: string, type: string, options: array<string, string>}  $column
     */
    private static function cell(array $column, mixed $answer, string $locale): string
    {
        if ($answer === null) {
            return '';
        }

        return match (FieldType::tryFrom($column['type'])) {
            FieldType::Checkbox => __($answer ? 'submission_export.yes' : 'submission_export.no', locale: $locale),
            FieldType::SingleChoice => is_string($answer) ? ($column['options'][$answer] ?? $answer) : '',
            FieldType::MultipleChoice => is_array($answer)
                ? implode('; ', array_map(static fn (mixed $value): string => $column['options'][(string) $value] ?? (string) $value, $answer))
                : '',
            // Los archivos salen solo como cantidad, nunca como enlace ni nombre.
            FieldType::File => is_array($answer) ? (string) count($answer) : '0',
            default => is_scalar($answer) ? (string) $answer : '',
        };
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, string> valor de la opción => etiqueta
     */
    private static function options(array $field, string $locale): array
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $options = [];

        foreach (is_array($config['options'] ?? null) ? $config['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $options[$option['value']] = self::text($option['label'] ?? null, $locale) ?: $option['value'];
            }
        }

        return $options;
    }

    private static function text(mixed $localized, string $locale): string
    {
        if (! is_array($localized)) {
            return '';
        }

        if (is_string($localized[$locale] ?? null) && $localized[$locale] !== '') {
            return $localized[$locale];
        }

        foreach ($localized as $text) {
            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return '';
    }

    private static function author(Submission $submission): ?SubmissionParticipant
    {
        return $submission->participants->firstWhere('is_primary', true) ?? $submission->participants->first();
    }
}
