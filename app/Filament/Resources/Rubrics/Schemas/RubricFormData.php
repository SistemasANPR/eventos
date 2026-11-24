<?php

declare(strict_types=1);

namespace App\Filament\Resources\Rubrics\Schemas;

use App\Enums\CriterionKind;
use App\Models\Rubric;
use App\Models\RubricCriterion;

/** Los datos de una rúbrica con la forma del formulario del panel (plan 011, F4b): para editarla o copiarla. */
final class RubricFormData
{
    /** @return array<string, mixed> */
    public static function from(Rubric $rubric): array
    {
        return [
            'submission_type_id' => $rubric->submission_type_id,
            'min_reviewers' => $rubric->min_reviewers,
            'discrepancy_threshold' => $rubric->discrepancy_threshold,
            'blind_review' => $rubric->blind_review,
            'criteria' => $rubric->criteria->map(static fn (RubricCriterion $criterion): array => [
                'label' => $criterion->getTranslations('label'),
                'kind' => $criterion->kind->value,
                'weight' => (float) $criterion->weight,
                // La escala de un criterio de comentario no se usa ni se muestra.
                'scale_min' => $criterion->kind === CriterionKind::Scale ? $criterion->scale_min : null,
                'scale_max' => $criterion->kind === CriterionKind::Scale ? $criterion->scale_max : null,
            ])->all(),
        ];
    }
}
