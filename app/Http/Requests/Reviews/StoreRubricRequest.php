<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Enums\CriterionKind;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\Rubric;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una rúbrica y sus criterios (plan 011, H-011-1, CA-011-1 y -2),
 * reutilizadas por las Actions y por Filament. Cada criterio pesa más que cero,
 * tiene `scale_min < scale_max` (si es de calificación) y la discrepancia de la
 * rúbrica es mayor que cero y no excede la amplitud de la escala más corta. La
 * etiqueta es obligatoria en el idioma base del evento.
 */
class StoreRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [Rubric::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');
        $criteria = $this->input('criteria');

        return $event instanceof Event ? self::rulesFor($event, is_array($criteria) ? $criteria : []) : [];
    }

    /**
     * @param  array<array-key, mixed>  $criteria  los criterios que llegan, para comparar la discrepancia con sus escalas
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, array $criteria): array
    {
        return [
            'submission_type_id' => [
                'required', 'uuid',
                Rule::exists('submission_types', 'id')
                    ->where('event_id', $event->id)
                    ->where('organization_id', $event->organization_id),
            ],
            'blind_review' => ['sometimes', 'boolean'],
            'min_reviewers' => ['required', 'integer', 'min:1', 'max:50'],
            'discrepancy_threshold' => ['required', 'integer', 'gt:0', self::withinShortestScale($criteria)],
            'criteria' => ['required', 'array', 'min:1', 'max:50'],
            'criteria.*.label' => ['required', 'array:'.implode(',', StoreEventRequest::LOCALES), self::defaultLocaleText($event)],
            'criteria.*.label.*' => ['nullable', 'string', 'max:200'],
            'criteria.*.kind' => ['required', Rule::enum(CriterionKind::class)],
            'criteria.*.scale_min' => ['required_if:criteria.*.kind,scale', 'nullable', 'integer', 'between:-100,100'],
            'criteria.*.scale_max' => ['required_if:criteria.*.kind,scale', 'nullable', 'integer', 'between:-100,100', 'gt:criteria.*.scale_min'],
            'criteria.*.weight' => ['required', 'numeric', 'gt:0', 'max:1000'],
        ];
    }

    /**
     * La discrepancia se mide en unidades de la escala: no puede ser mayor que la amplitud de la escala más corta.
     *
     * @param  array<array-key, mixed>  $criteria
     */
    private static function withinShortestScale(array $criteria): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($criteria): void {
            $amplitude = self::shortestAmplitude($criteria);

            if ($amplitude !== null && is_numeric($value) && (int) $value > $amplitude) {
                $fail('reviews.discrepancy_exceeds_scale')->translate(['amplitude' => $amplitude]);
            }
        };
    }

    /**
     * @param  array<array-key, mixed>  $criteria
     */
    public static function shortestAmplitude(array $criteria): ?int
    {
        $amplitudes = [];

        foreach ($criteria as $criterion) {
            if (is_array($criterion) && ($criterion['kind'] ?? null) === CriterionKind::Scale->value
                && is_numeric($criterion['scale_min'] ?? null) && is_numeric($criterion['scale_max'] ?? null)
                && (int) $criterion['scale_max'] > (int) $criterion['scale_min']) {
                $amplitudes[] = (int) $criterion['scale_max'] - (int) $criterion['scale_min'];
            }
        }

        return $amplitudes === [] ? null : min($amplitudes);
    }

    private static function defaultLocaleText(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            $text = is_array($value) ? ($value[$event->default_locale] ?? null) : null;

            if (! is_string($text) || trim($text) === '') {
                $fail('reviews.criterion_label_required')->translate();
            }
        };
    }
}
