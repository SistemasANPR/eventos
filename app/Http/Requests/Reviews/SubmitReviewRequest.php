<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Enums\CriterionKind;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\RubricCriterion;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de una evaluación (plan 011, H-011-3, CA-011-13 y 14): cada respuesta se valida contra el criterio
 * de la versión de rúbrica publicada, no contra lo que declare el cliente. Un criterio de calificación es
 * obligatorio al enviar y su valor es un entero dentro de `scale_min` y `scale_max`; el criterio de texto
 * es un comentario opcional. El borrador (`$draft`) exige lo mismo de cada valor presente, pero no que
 * estén todos. Una clave que no es criterio de la rúbrica se rechaza.
 */
final class SubmitReviewRequest extends FormRequest
{
    public const MAX_TEXT_LENGTH = 5000;

    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof ReviewAssignment && Gate::allows('submit', [Review::class, $assignment]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /**
     * @param  iterable<RubricCriterion>  $criteria  criterios de la versión de rúbrica con que se evalúa
     * @return array<string, mixed>
     */
    public static function rulesFor(iterable $criteria, bool $draft): array
    {
        $ids = [];
        $rules = [];

        foreach ($criteria as $criterion) {
            $ids[] = $criterion->id;
            $rules["answers.{$criterion->id}"] = match ($criterion->kind) {
                CriterionKind::Scale => [
                    $draft ? 'nullable' : 'required',
                    'integer',
                    "between:{$criterion->scale_min},{$criterion->scale_max}",
                ],
                CriterionKind::Text => ['nullable', 'string', 'max:'.self::MAX_TEXT_LENGTH],
            };
        }

        $rules['answers'] = [
            'array',
            static function (string $attribute, mixed $value, Closure $fail) use ($ids): void {
                if (is_array($value) && array_diff(array_map('strval', array_keys($value)), $ids) !== []) {
                    $fail('reviewer.unknown_criterion')->translate();
                }
            },
        ];

        return $rules;
    }
}
