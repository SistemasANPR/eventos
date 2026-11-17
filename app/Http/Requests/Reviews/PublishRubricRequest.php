<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Enums\CriterionKind;
use App\Models\Event;
use App\Models\Rubric;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para publicar una rúbrica (plan 011, H-011-1): las de guardarla más una
 * propia, que tenga al menos un criterio de calificación (sin él no hay nada que
 * promediar). Se aplican al contenido guardado de la rúbrica, no a lo que
 * llegue del cliente.
 */
final class PublishRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rubric = $this->route('rubric');

        return $rubric instanceof Rubric && Gate::allows('publish', $rubric);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /**
     * @param  array<array-key, mixed>  $criteria
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, array $criteria): array
    {
        $rules = StoreRubricRequest::rulesFor($event, $criteria);
        $rules['criteria'][] = static function (string $attribute, mixed $value, Closure $fail): void {
            $hasScale = is_array($value) && array_filter(
                $value,
                static fn (mixed $criterion): bool => is_array($criterion) && ($criterion['kind'] ?? null) === CriterionKind::Scale->value,
            ) !== [];

            if (! $hasScale) {
                $fail('reviews.publish_needs_scale_criterion')->translate();
            }
        };

        return $rules;
    }
}
