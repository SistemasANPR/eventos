<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CriterionKind;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RubricCriterion> */
class RubricCriterionFactory extends Factory
{
    protected $model = RubricCriterion::class;

    /**
     * Criterio de calificación 1 a 5. Acepta `rubric_id` explícito; hereda la
     * organización de la rúbrica (lo exige la base).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (array $attributes): string => is_string($attributes['rubric_id'] ?? null)
                ? (string) Rubric::query()->withoutGlobalScopes()->whereKey($attributes['rubric_id'])->value('organization_id')
                : Rubric::factory()->create()->organization_id,
            'rubric_id' => fn (array $attributes): string => Rubric::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'position' => 1,
            'label' => ['es' => 'Relevancia'],
            'kind' => CriterionKind::Scale,
            'scale_min' => 1,
            'scale_max' => 5,
            'weight' => 1,
        ];
    }

    public function text(): static
    {
        return $this->state(['kind' => CriterionKind::Text]);
    }
}
