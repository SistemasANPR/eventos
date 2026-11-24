<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewRevision> */
class ReviewRevisionFactory extends Factory
{
    protected $model = ReviewRevision::class;

    /**
     * Versión anterior de una evaluación. Hereda organización y rúbrica de la evaluación; acepta
     * `organization_id` o `review_id` explícitos.
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

                return Review::factory()->create()->organization_id;
            },
            'review_id' => fn (array $attributes): string => Review::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'rubric_id' => fn (array $attributes): string => (string) Review::query()->withoutGlobalScopes()->whereKey($attributes['review_id'])->value('rubric_id'),
            'revision' => 1,
            'submitted_at' => now(),
            'answers' => [],
        ];
    }
}
