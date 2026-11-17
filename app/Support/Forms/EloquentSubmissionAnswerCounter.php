<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\FormDefinition;
use App\Models\Submission;

/**
 * Implementación real de `SubmissionAnswerCounter` (plan 006): consulta las propuestas
 * por el modelo, así que respeta el filtro de organización (falla sin contexto).
 */
final class EloquentSubmissionAnswerCounter implements SubmissionAnswerCounter
{
    public function hasAnswers(FormDefinition $form): bool
    {
        return Submission::query()->where('form_definition_id', $form->id)->exists();
    }

    public function optionIsUsed(FormDefinition $form, string $fieldKey, string $optionValue): bool
    {
        // `whereJsonContains` cubre la opción única (texto) y la múltiple (lista) con enlaces.
        return Submission::query()
            ->where('form_definition_id', $form->id)
            ->whereJsonContains('answers->'.$fieldKey, $optionValue)
            ->exists();
    }
}
