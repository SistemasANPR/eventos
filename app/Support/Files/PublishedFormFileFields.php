<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Enums\FieldType;
use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;

/** Implementación real de `FileFieldRegistry`: la clave es un campo de tipo archivo del formulario publicado de la convocatoria. */
final class PublishedFormFileFields implements FileFieldRegistry
{
    public function exists(CallForSubmissions $call, string $fieldKey): bool
    {
        $form = $call->formDefinition;

        if ($form === null || $form->status !== FormDefinitionStatus::Published) {
            return false;
        }

        foreach ($form->fields as $field) {
            if (($field['key'] ?? null) === $fieldKey && ($field['type'] ?? null) === FieldType::File->value) {
                return true;
            }
        }

        return false;
    }
}
