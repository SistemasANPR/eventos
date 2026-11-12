<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\FormDefinition;

/**
 * Pregunta si un formulario ya tiene respuestas (plan 005, DA-3, CA-005-18 a CA-005-20).
 * Las propuestas llegan con el plan 006, que reemplaza la implementación nula por la real.
 */
interface SubmissionAnswerCounter
{
    /** `true` si alguna propuesta guardó respuestas contra este formulario. */
    public function hasAnswers(FormDefinition $form): bool;

    /** `true` si alguna respuesta eligió esta opción del campo. */
    public function optionIsUsed(FormDefinition $form, string $fieldKey, string $optionValue): bool;
}
