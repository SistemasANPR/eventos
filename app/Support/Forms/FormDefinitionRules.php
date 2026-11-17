<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Actions\Forms\SanitizeFormText;
use App\Enums\FieldType;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as FormValidator;

/**
 * Reglas de validación de la definición de un formulario (plan 005, H-005-1,
 * CA-005-2 a CA-005-5 y CA-005-8), compartidas por los Form Requests y por las
 * Actions. Primero la forma de cada campo (rutas con comodín) y después las
 * comprobaciones que cruzan valores: límites, opciones, claves repetidas y
 * `identifiesAuthor`. `validated()` solo devuelve lo que las reglas declaran, así
 * que una clave desconocida del cliente nunca llega a guardarse.
 */
final class FormDefinitionRules
{
    public const MAX_FIELDS = 40;

    public const MAX_OPTIONS = 50;

    /** Extensiones que un campo `file` puede aceptar (CA-005-4). */
    public const FILE_EXTENSIONS = ['pdf', 'pptx', 'jpg', 'png'];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validate(array $data, Event $event): array
    {
        $validator = Validator::make($data, self::rules());
        self::addCrossChecks($validator, $event);

        return $validator->validate();
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $locales = 'array:'.implode(',', StoreEventRequest::LOCALES);

        return [
            'name' => ['required', 'string', 'max:150'],
            'fields' => ['present', 'array', 'max:'.self::MAX_FIELDS],
            'fields.*' => ['array'],
            'fields.*.key' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['required', Rule::enum(FieldType::class)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.identifiesAuthor' => ['sometimes', self::strictBoolean()],
            'fields.*.label' => ['required', $locales],
            'fields.*.label.*' => ['nullable', 'string', 'max:200'],
            'fields.*.help' => ['nullable', $locales],
            'fields.*.help.*' => ['nullable', 'string', 'max:500'],
            'fields.*.config' => ['nullable', 'array'],
            'fields.*.config.minLength' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'fields.*.config.maxLength' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'fields.*.config.min' => ['nullable', 'numeric'],
            'fields.*.config.max' => ['nullable', 'numeric'],
            'fields.*.config.maxSelections' => ['nullable', 'integer', 'min:1'],
            'fields.*.config.options' => ['nullable', 'array', 'max:'.self::MAX_OPTIONS],
            'fields.*.config.options.*' => ['array'],
            'fields.*.config.options.*.value' => ['nullable', 'string', 'max:64'],
            'fields.*.config.options.*.label' => ['required', $locales],
            'fields.*.config.options.*.label.*' => ['nullable', 'string', 'max:200'],
            'fields.*.config.accept' => ['nullable', 'array'],
            'fields.*.config.accept.*' => ['string', Rule::in(self::FILE_EXTENSIONS)],
            'fields.*.config.maxFiles' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    /** Añade al validador las comprobaciones que cruzan valores de un mismo campo o de varios. */
    public static function addCrossChecks(FormValidator $validator, Event $event): void
    {
        $validator->after(static function (FormValidator $validator) use ($event): void {
            $fields = $validator->getData()['fields'] ?? null;

            if (! is_array($fields)) {
                return;
            }

            $seenKeys = [];

            // Se conservan las claves del arreglo: el repetidor de Filament las usa como identificador
            // de cada fila y los errores solo se pintan junto a su campo si la ruta coincide.
            foreach ($fields as $index => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $path = 'fields.'.$index;
                self::checkLabel($validator, $path, $field, $event);
                self::checkIdentifiesAuthor($validator, $path, $field);
                self::checkConfig($validator, $path, $field, $event);
                self::checkKey($validator, $path, $field, $seenKeys);
            }
        });
    }

    /** `boolean` de Laravel acepta 1, 0 y "1": aquí solo vale `true` o `false` (CA-005-8). */
    private static function strictBoolean(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_bool($value)) {
                $fail('forms.identifies_author_not_boolean')->translate();
            }
        };
    }

    /** @param  array<string, mixed>  $field */
    private static function checkLabel(FormValidator $validator, string $path, array $field, Event $event): void
    {
        $label = is_array($field['label'] ?? null) ? ($field['label'][$event->default_locale] ?? null) : null;

        if (! is_string($label) || app(SanitizeFormText::class)->handle($label) === '') {
            $validator->errors()->add($path.'.label.'.$event->default_locale, __('forms.label_required'));
        }
    }

    /** @param  array<string, mixed>  $field */
    private static function checkIdentifiesAuthor(FormValidator $validator, string $path, array $field): void
    {
        $type = self::typeOf($field);

        if (($field['identifiesAuthor'] ?? false) === true && $type !== null && ! $type->canIdentifyAuthor()) {
            $validator->errors()->add($path.'.identifiesAuthor', __('forms.identifies_author_type'));
        }
    }

    /** @param  array<string, mixed>  $field */
    private static function checkConfig(FormValidator $validator, string $path, array $field, Event $event): void
    {
        $type = self::typeOf($field);
        $config = is_array($field['config'] ?? null) ? $field['config'] : [];

        if ($type === FieldType::ShortText || $type === FieldType::LongText) {
            self::checkRange($validator, $path, $config, 'minLength', 'maxLength');
        }

        if ($type === FieldType::Number) {
            self::checkRange($validator, $path, $config, 'min', 'max');
        }

        if ($type?->hasOptions()) {
            self::checkOptions($validator, $path, $config, $event, $type === FieldType::MultipleChoice);
        }
    }

    /** @param  array<string, mixed>  $config */
    private static function checkRange(FormValidator $validator, string $path, array $config, string $low, string $high): void
    {
        if (is_numeric($config[$low] ?? null) && is_numeric($config[$high] ?? null) && $config[$low] > $config[$high]) {
            $validator->errors()->add($path.'.config.'.$low, __('forms.range_inverted'));
        }
    }

    /** @param  array<string, mixed>  $config */
    private static function checkOptions(FormValidator $validator, string $path, array $config, Event $event, bool $multiple): void
    {
        $options = is_array($config['options'] ?? null) ? array_values($config['options']) : [];

        if (count($options) < 2) {
            $validator->errors()->add($path.'.config.options', __('forms.options_min'));

            return;
        }

        $sanitize = app(SanitizeFormText::class);
        $labels = [];
        $values = [];

        foreach ($options as $option) {
            $label = is_array($option) && is_array($option['label'] ?? null) ? ($option['label'][$event->default_locale] ?? null) : null;
            $label = is_string($label) ? mb_strtolower($sanitize->handle($label)) : '';

            if ($label === '') {
                $validator->errors()->add($path.'.config.options', __('forms.option_label_required'));

                return;
            }

            $labels[] = $label;

            if (is_array($option) && is_string($option['value'] ?? null) && $option['value'] !== '') {
                $values[] = $option['value'];
            }
        }

        if (count(array_unique($labels)) !== count($labels) || count(array_unique($values)) !== count($values)) {
            $validator->errors()->add($path.'.config.options', __('forms.options_repeated'));
        }

        if ($multiple && is_numeric($config['maxSelections'] ?? null) && (int) $config['maxSelections'] > count($options)) {
            $validator->errors()->add($path.'.config.maxSelections', __('forms.max_selections_exceeds_options'));
        }
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, true>  $seenKeys
     */
    private static function checkKey(FormValidator $validator, string $path, array $field, array &$seenKeys): void
    {
        $key = $field['key'] ?? null;

        if (! is_string($key) || $key === '') {
            return;
        }

        if (isset($seenKeys[$key])) {
            $validator->errors()->add($path.'.key', __('forms.key_repeated'));

            return;
        }

        $seenKeys[$key] = true;
    }

    /** @param  array<string, mixed>  $field */
    private static function typeOf(array $field): ?FieldType
    {
        return is_string($field['type'] ?? null) ? FieldType::tryFrom($field['type']) : null;
    }
}
