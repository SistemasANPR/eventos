<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Actions\Forms\SanitizeFormText;
use App\Enums\FieldType;
use App\Models\Event;
use App\Support\Events\OfferedLocaleTexts;
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
     * @param  Event|null  $event  si se indica, de los campos y opciones que ya existían se conservan los textos
     *                             de los idiomas que el evento dejó de ofrecer (plan 026, DA-1)
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException si la definición supera el tamaño máximo
     */
    public function normalize(array $fields, array $current = [], ?Event $event = null): array
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

            $role = ProposalFields::roleOf($field);

            $entry = [
                'key' => $key,
                'type' => $type->value,
                // El título y el tema siempre son obligatorios: la propuesta no existe sin ellos.
                'required' => $role !== null || ($field['required'] ?? false) === true || ($field['required'] ?? false) === 1,
                'position' => $index + 1,
                'identifiesAuthor' => ($field['identifiesAuthor'] ?? false) === true,
                'label' => $this->kept($this->texts($field['label']), $existing['label'] ?? [], $event),
                'help' => $this->kept($this->texts($field['help'] ?? []), $existing['help'] ?? [], $event),
                'config' => $this->config($type, $field['config'] ?? [], $existing['config']['options'] ?? [], $event),
                'retiredAt' => $existing['retiredAt'] ?? null,
            ];

            // Solo se guarda cuando la hay: los formularios anteriores quedan iguales.
            if ($role !== null) {
                $entry['role'] = $role;
            }

            $normalized[] = $entry;
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
    private function config(FieldType $type, array $config, array $storedOptions, ?Event $event): array
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
            FieldType::SingleChoice => ['options' => $this->options($config['options'] ?? [], $storedOptions, $event)],            FieldType::MultipleChoice => $this->present([
                'options' => $this->options($config['options'] ?? [], $storedOptions, $event),
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
    private function options(array $options, array $storedOptions, ?Event $event): array
    {
        $takenValues = array_fill_keys(array_map('strval', array_column($storedOptions, 'value')), true);
        $normalized = [];

        foreach (array_values($options) as $option) {
            $clientValue = $option['value'] ?? null;
            $isKnown = is_string($clientValue) && in_array($clientValue, array_column($storedOptions, 'value'), true);
            $stored = $isKnown ? ($storedOptions[array_search($clientValue, array_column($storedOptions, 'value'), true)]['label'] ?? []) : [];

            $normalized[] = [
                'value' => $isKnown ? $clientValue : $this->generate('o_', $takenValues),
                'label' => $this->kept($this->texts($option['label']), $stored, $event),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $incoming
     * @param  array<array-key, mixed>  $stored
     * @return array<string, string>
     */
    private function kept(array $incoming, array $stored, ?Event $event): array
    {
        return $event === null ? $incoming : OfferedLocaleTexts::withUnoffered($incoming, $stored, $event);
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
