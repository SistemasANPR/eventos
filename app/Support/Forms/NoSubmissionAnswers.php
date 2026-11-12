<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\FormDefinition;

/**
 * Implementación por defecto de `SubmissionAnswerCounter`: todavía no existen las
 * propuestas (plan 006), así que ningún formulario tiene respuestas.
 */
final class NoSubmissionAnswers implements SubmissionAnswerCounter
{
    public function hasAnswers(FormDefinition $form): bool
    {
        return false;
    }

    public function optionIsUsed(FormDefinition $form, string $fieldKey, string $optionValue): bool
    {
        return false;
    }
}
