<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Enums\FieldType;
use App\Exceptions\Domain\FieldHasAnswers;
use App\Exceptions\Domain\FormChangeNotAllowed;
use App\Models\FormDefinition;

/**
 * Qué se puede cambiar de un formulario que ya tiene respuestas (plan 005, DA-2,
 * CA-005-18 a CA-005-20): no se elimina un campo ni cambia su tipo, no se añaden
 * campos obligatorios y no se quita una opción ya elegida. Se puede editar textos,
 * añadir campos opcionales y añadir opciones.
 */
final class FormChangeRules
{
    public function __construct(private readonly SubmissionAnswerCounter $answers) {}

    /**
     * @param  list<array<string, mixed>>  $newFields  campos validados que envía el cliente (aún con sus claves sin normalizar)
     *
     * @throws FieldHasAnswers
     * @throws FormChangeNotAllowed
     */
    public function assertAllowed(FormDefinition $form, array $newFields): void
    {
        if (! $this->answers->hasAnswers($form)) {
            return;
        }

        $submitted = [];
        foreach ($newFields as $field) {
            if (is_string($field['key'] ?? null) && $field['key'] !== '') {
                $submitted[$field['key']] = $field;
            }
        }

        $stored = [];
        foreach ($form->fields as $field) {
            $key = (string) $field['key'];
            $stored[$key] = true;

            if (! isset($submitted[$key])) {
                throw new FieldHasAnswers($key);
            }

            $this->assertExistingFieldChange($form, $field, $submitted[$key]);
        }

        foreach ($newFields as $field) {
            $isNew = ! is_string($field['key'] ?? null) || ! isset($stored[$field['key']]);

            if ($isNew && ($field['required'] ?? false) === true) {
                throw FormChangeNotAllowed::requiredFieldAdded((string) ($field['label'][array_key_first($field['label'])] ?? ''));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $submitted
     */
    private function assertExistingFieldChange(FormDefinition $form, array $stored, array $submitted): void
    {
        $key = (string) $stored['key'];

        if ($stored['type'] !== ($submitted['type'] ?? null)) {
            throw FormChangeNotAllowed::typeChanged($key);
        }

        if (! FieldType::from((string) $stored['type'])->hasOptions()) {
            return;
        }

        $kept = [];
        foreach ($submitted['config']['options'] ?? [] as $option) {
            if (is_string($option['value'] ?? null)) {
                $kept[$option['value']] = true;
            }
        }

        foreach ($stored['config']['options'] ?? [] as $option) {
            $value = (string) $option['value'];

            if (! isset($kept[$value]) && $this->answers->optionIsUsed($form, $key, $value)) {
                throw FormChangeNotAllowed::usedOptionRemoved($key);
            }
        }
    }
}
