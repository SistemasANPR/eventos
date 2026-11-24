<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAnswer> */
class ReviewAnswerFactory extends Factory
{
    protected $model = ReviewAnswer::class;

    /**
     * Respuesta numérica. Evaluación y criterio comparten la organización (lo exige la base); acepta
     * `organization_id`, `review_id` o `rubric_criterion_id` explícitos.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['review_id'] ?? null)) {
                    return (string) Review::query()->withoutGlobalScopes()->whereKey($attributes['review_id'])->value('organization_id');
                }
                if (is_string($attributes['rubric_criterion_id'] ?? null)) {
                    return (string) RubricCriterion::query()->withoutGlobalScopes()->whereKey($attributes['rubric_criterion_id'])->value('organization_id');
                }

                return Review::factory()->create()->organization_id;
            },
            'review_id' => fn (array $attributes): string => Review::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'rubric_criterion_id' => fn (array $attributes): string => RubricCriterion::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'rubric_id' => Rubric::factory()->create(['organization_id' => $attributes['organization_id']])->id])
                ->id,
            'value_int' => 4,
            'value_text' => null,
        ];
    }

    public function text(string $text = 'Comentario'): static
    {
        return $this->state(['value_int' => null, 'value_text' => $text]);
    }
}
