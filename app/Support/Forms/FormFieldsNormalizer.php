<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Actions\Forms\SanitizeFormText;
use App\Enums\FieldType;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Convierte los campos ya validados que envía el cliente en la definición que se
 * guarda (plan 005, H-005-1, DA-1): el servidor genera la clave de cada campo y el
 * valor de cada opción, las conserva al editar y ignora cualquier clave inventada
 * por el cliente (CA-005-7); limpia los textos a texto plano (CA-005-3), numera las
 * posiciones sin huecos y verifica que la definición no pase de 64 KB (CA-005-5).
 */
final class FormFieldsNormalizer
{
    public const MAX_DEFINITION_BYTES = 65536;

    public function __construct(private readonly SanitizeFormText $sanitize) {}

    /**
     * @param  list<array<string, mixed>>  $fields  campos validados por `FormDefinitionRules`
     * @param  list<array<string, mixed>>  $current  campos ya guardados, para conservar claves y valores
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException si la definición supera el tamaño máximo
     */
    public function normalize(array $fields, array $current = []): array
    {
        $currentByKey = [];
        foreach ($current as $stored) {
            $currentByKey[(string) $stored['key']] = $stored;
        }

        $takenKeys = array_fill_keys(array_keys($currentByKey), true);
        $normalized = [];

        foreach (array_values($fields) as $index => $field) {
            $type = FieldType::from((string) $field['type']);
            $clientKey = $field['key'] ?? null;
            $existing = is_string($clientKey) ? ($currentByKey[$clientKey] ?? null) : null;

            if ($existing !== null) {
                $key = (string) $existing['key'];
            } else {
                $key = $this->generate('f_', $takenKeys);
            }

            $normalized[] = [
                'key' => $key,
                'type' => $type->value,
                'required' => ($field['required'] ?? false) === true || ($field['required'] ?? false) === 1,
                'position' => $index + 1,
                'identifiesAuthor' => ($field['identifiesAuthor'] ?? false) === true,
                'label' => $this->texts($field['label']),
                'help' => $this->texts($field['help'] ?? []),
                'config' => $this->config($type, $field['config'] ?? [], $existing['config']['options'] ?? []),
                'retiredAt' => $existing['retiredAt'] ?? null,
            ];
        }

        if (strlen((string) json_encode($normalized, JSON_UNESCAPED_UNICODE)) > self::MAX_DEFINITION_BYTES) {
            throw ValidationException::withMessages(['fields' => __('forms.definition_too_large')]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $storedOptions
     * @return array<string, mixed>
     */
    private function config(FieldType $type, array $config, array $storedOptions): array
    {
        $accept = array_values(array_unique($config['accept'] ?? []));

        return match ($type) {
            FieldType::ShortText, FieldType::LongText => $this->present([
                'minLength' => $this->number($config['minLength'] ?? null),
                'maxLength' => $this->number($config['maxLength'] ?? null),
            ]),
            FieldType::Number => $this->present([
                'min' => $this->number($config['min'] ?? null),
                'max' => $this->number($config['max'] ?? null),
            ]),
            FieldType::SingleChoice => ['options' => $this->options($config['options'] ?? [], $storedOptions)],
            FieldType::MultipleChoice => $this->present([
                'options' => $this->options($config['options'] ?? [], $storedOptions),
                'maxSelections' => $this->number($config['maxSelections'] ?? null),
            ]),
            FieldType::File => $this->present([
                'accept' => $accept === [] ? null : $accept,
                'maxFiles' => $this->number($config['maxFiles'] ?? null),
            ]),
            default => [],
        };
    }

    /** Un límite llega como texto desde el panel («5»): se guarda como número; vacío o no numérico es «sin límite». */
    private function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @param  list<array<string, mixed>>  $storedOptions
     * @return list<array{value: string, label: array<string, string>}>
     */
    private function options(array $options, array $storedOptions): array
    {
        $takenValues = array_fill_keys(array_map('strval', array_column($storedOptions, 'value')), true);
        $normalized = [];

        foreach (array_values($options) as $option) {
            $clientValue = $option['value'] ?? null;
            $isKnown = is_string($clientValue) && in_array($clientValue, array_column($storedOptions, 'value'), true);

            $normalized[] = [
                'value' => $isKnown ? $clientValue : $this->generate('o_', $takenValues),
                'label' => $this->texts($option['label']),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        return array_filter(
            array_map(fn (mixed $text): string => is_string($text) ? $this->sanitize->handle($text) : '', $texts),
            static fn (string $text): bool => $text !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    /** @param  array<string, true>  $taken */
    private function generate(string $prefix, array &$taken): string
    {
        do {
            $candidate = $prefix.Str::lower(Str::random(8));
        } while (isset($taken[$candidate]));

        $taken[$candidate] = true;

        return $candidate;
    }
}
