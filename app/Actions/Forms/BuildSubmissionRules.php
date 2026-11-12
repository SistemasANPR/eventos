<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Enums\FieldType;
use App\Support\Forms\Countries;
use App\Support\Forms\PendingUploadRepository;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as AnswersValidator;

/**
 * Única fuente de reglas para validar las respuestas de una propuesta contra la
 * definición de su formulario (plan 005, H-005-4). La usan por igual el alta y la
 * edición de propuestas: el cliente nunca decide qué es válido.
 *
 * Los errores se asocian a `answers.<clave del campo>`. Solo se devuelven las
 * claves de campos vigentes; las desconocidas o de campos retirados se rechazan.
 */
final class BuildSubmissionRules
{
    /** Tamaño máximo de las respuestas serializadas (CA-005-33). */
    public const MAX_ANSWERS_BYTES = 262144;

    private const DEFAULT_SHORT_LENGTH = 255;

    private const DEFAULT_LONG_LENGTH = 10000;

    public function __construct(private readonly PendingUploadRepository $uploads) {}

    /**
     * @param  list<array<string, mixed>>  $fields  definición guardada del formulario
     * @return array<string, list<mixed>> reglas por `answers.<clave>`
     */
    public function rules(array $fields, string $uploadToken, string $organizationId): array
    {
        $rules = [];

        foreach ($this->activeFields($fields) as $field) {
            $path = 'answers.'.$field['key'];
            $rules[$path] = $this->fieldRules($field, $uploadToken, $organizationId);

            // Cada elemento de una selección múltiple debe ser una de las opciones definidas.
            if ($field['type'] === FieldType::MultipleChoice->value) {
                $config = is_array($field['config'] ?? null) ? $field['config'] : [];
                $rules[$path.'.*'] = ['string', 'distinct:strict', Rule::in($this->optionValues($config))];
            }
        }

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed> respuestas limpias, solo de campos vigentes
     *
     * @throws ValidationException
     */
    public function validate(array $fields, array $answers, string $uploadToken, string $organizationId): array
    {
        $allowed = array_column($this->activeFields($fields), 'key');
        $clean = $this->withoutControlCharacters($answers);
        $validator = Validator::make(['answers' => $clean], $this->rules($fields, $uploadToken, $organizationId));

        $validator->after(function (AnswersValidator $validator) use ($allowed, $clean): void {
            foreach (array_keys($clean) as $key) {
                if (! in_array((string) $key, $allowed, true)) {
                    $validator->errors()->add('answers.'.$key, __('forms.answer_unknown_field'));
                }
            }

            if (strlen((string) json_encode($clean)) > self::MAX_ANSWERS_BYTES) {
                $validator->errors()->add('answers', __('forms.answers_too_large'));
            }
        });

        $validator->validate();

        return array_intersect_key($clean, array_flip($allowed));
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function activeFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            static fn (array $field): bool => ($field['retiredAt'] ?? null) === null,
        ));
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function withoutControlCharacters(array $answers): array
    {
        return array_map(function (mixed $value): mixed {
            if (is_string($value)) {
                return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
            }

            return is_array($value) ? $this->withoutControlCharacters($value) : $value;
        }, $answers);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<mixed>
     */
    private function fieldRules(array $field, string $uploadToken, string $organizationId): array
    {
        $type = FieldType::from((string) $field['type']);
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];
        $required = ($field['required'] ?? false) === true;

        return [
            ...$this->presenceRules($type, $required),
            ...match ($type) {
                FieldType::ShortText => $this->textRules($config, self::DEFAULT_SHORT_LENGTH),
                FieldType::LongText => $this->textRules($config, self::DEFAULT_LONG_LENGTH),
                FieldType::Number => $this->numberRules($config),
                FieldType::SingleChoice => ['string', Rule::in($this->optionValues($config))],
                FieldType::MultipleChoice => $this->multipleChoiceRules($config),
                FieldType::Checkbox => [],
                FieldType::Date => ['string', 'date_format:Y-m-d'],
                FieldType::Country => ['string', Rule::in(Countries::CODES)],
                FieldType::File => $this->fileRules($config, $uploadToken, $organizationId),
            },
        ];
    }

    /** @return list<string> */
    private function presenceRules(FieldType $type, bool $required): array
    {
        if ($type === FieldType::Checkbox) {
            return $required ? ['accepted'] : ['nullable', 'boolean'];
        }

        if ($type === FieldType::MultipleChoice || $type === FieldType::File) {
            return $required ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
        }

        return $required ? ['required'] : ['nullable'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function textRules(array $config, int $defaultMax): array
    {
        $max = is_numeric($config['maxLength'] ?? null) ? (int) $config['maxLength'] : $defaultMax;
        $rules = ['string', 'max:'.$max];

        if (is_numeric($config['minLength'] ?? null) && (int) $config['minLength'] > 0) {
            $rules[] = 'min:'.(int) $config['minLength'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function numberRules(array $config): array
    {
        $rules = ['numeric'];

        if (is_numeric($config['min'] ?? null)) {
            $rules[] = 'gte:'.$config['min'];
        }

        if (is_numeric($config['max'] ?? null)) {
            $rules[] = 'lte:'.$config['max'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<mixed>
     */
    private function multipleChoiceRules(array $config): array
    {
        $rules = [];

        if (is_numeric($config['maxSelections'] ?? null)) {
            $rules[] = 'max:'.(int) $config['maxSelections'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function optionValues(array $config): array
    {
        $options = is_array($config['options'] ?? null) ? $config['options'] : [];
        $values = [];

        foreach ($options as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<mixed>
     */
    private function fileRules(array $config, string $uploadToken, string $organizationId): array
    {
        $rules = [];

        if (is_numeric($config['maxFiles'] ?? null)) {
            $rules[] = 'max:'.(int) $config['maxFiles'];
        }

        $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($uploadToken, $organizationId): void {
            $ids = is_array($value) ? array_values($value) : [];

            if ($ids === []) {
                return;
            }

            if (array_filter($ids, 'is_string') !== $ids || ! $this->uploads->allBelongTo($ids, $uploadToken, $organizationId)) {
                $fail('forms.answer_file_invalid')->translate();
            }
        };

        return $rules;
    }
}
