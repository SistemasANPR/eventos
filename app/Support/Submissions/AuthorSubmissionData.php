<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\FieldType;
use App\Models\Event;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use Illuminate\Support\Carbon;

/**
 * Lo único que el autor ve de su propuesta (plan 006, CA-006-40): título, eje, estado, sus respuestas
 * con las etiquetas del formulario con que las envió (la instantánea, no la definición actual) y sus
 * participantes. Campos explícitos: nada de notas, evaluaciones, otras propuestas, identificadores de
 * archivos ni datos internos (`idempotency_key`, `payload_hash`). Las fechas viajan en UTC (ISO 8601).
 */
final class AuthorSubmissionData
{
    /**
     * @return array{reference: string, title: string, status: string, locale: string, version: int, submittedAt: string, withdrawnAt: ?string, track: string, answers: list<array{key: string, label: string, type: string, value: string|bool|list<string>|null}>, participants: list<array{fullName: string, email: string, country: string, jobTitle: ?string, company: ?string, bio: ?string, isPrimary: bool}>}
     */
    public static function from(Submission $submission, Event $event, string $locale): array
    {
        return [
            'reference' => SubmissionReference::for($submission),
            'title' => $submission->title,
            'status' => $submission->status->value,
            'locale' => $submission->locale,
            'version' => $submission->version,
            'submittedAt' => self::utc($submission->submitted_at),
            'withdrawnAt' => $submission->withdrawn_at === null ? null : self::utc($submission->withdrawn_at),
            'track' => (string) ($submission->track->getTranslation('name', $locale, false) ?: $submission->track->getTranslation('name', $event->default_locale)),
            'answers' => self::answers($submission, $locale, $event->default_locale),
            'participants' => $submission->participants->map(self::participant(...))->values()->all(),
        ];
    }

    /** @return list<array{key: string, label: string, type: string, value: string|bool|list<string>|null}> */
    private static function answers(Submission $submission, string $locale, string $fallbackLocale): array
    {
        $fields = is_array($submission->form_snapshot['fields'] ?? null) ? $submission->form_snapshot['fields'] : [];
        $rows = [];

        foreach ($fields as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            $type = is_array($field) ? FieldType::tryFrom((string) ($field['type'] ?? '')) : null;

            // Un campo de la instantánea sin respuesta (retirado después, o no contestado) no se lista.
            if (! is_string($key) || $type === null || ! array_key_exists($key, $submission->answers)) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => self::text($field['label'] ?? null, $locale, $fallbackLocale) ?: $key,
                'type' => $type->value,
                'value' => self::value($type, $field, $submission->answers[$key], $locale, $fallbackLocale),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return string|bool|list<string>|null
     */
    private static function value(FieldType $type, array $field, mixed $answer, string $locale, string $fallbackLocale): string|bool|array|null
    {
        return match ($type) {
            FieldType::Checkbox => (bool) $answer,
            FieldType::SingleChoice => is_string($answer) ? self::optionLabel($field, $answer, $locale, $fallbackLocale) : null,
            FieldType::MultipleChoice => is_array($answer)
                ? array_values(array_map(fn (mixed $value): string => self::optionLabel($field, (string) $value, $locale, $fallbackLocale), $answer))
                : [],
            // Los archivos no se exponen por identificador: solo cuántos se enviaron.
            FieldType::File => is_array($answer) ? (string) count($answer) : null,
            default => is_scalar($answer) ? (string) $answer : null,
        };
    }

    /** @param  array<string, mixed>  $field */
    private static function optionLabel(array $field, string $value, string $locale, string $fallbackLocale): string
    {
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];

        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $value) {
                return self::text($option['label'] ?? null, $locale, $fallbackLocale) ?: $value;
            }
        }

        return $value;
    }

    /** Texto por idioma con respaldo en el idioma base del evento y, al final, en el primero que exista. */
    private static function text(mixed $localized, string $locale, string $fallbackLocale): string
    {
        if (! is_array($localized)) {
            return '';
        }

        foreach ([$locale, $fallbackLocale] as $candidate) {
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

    /** @return array{fullName: string, email: string, country: string, jobTitle: ?string, company: ?string, bio: ?string, isPrimary: bool} */
    private static function participant(SubmissionParticipant $participant): array
    {
        return [
            'fullName' => $participant->full_name,
            'email' => $participant->email,
            'country' => $participant->country,
            'jobTitle' => $participant->job_title,
            'company' => $participant->company,
            'bio' => $participant->bio,
            'isPrimary' => $participant->is_primary,
        ];
    }

    private static function utc(Carbon $instant): string
    {
        return $instant->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
