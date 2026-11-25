<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Rubric;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Review> */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /**
     * Asignación y rúbrica comparten la organización de la evaluación (lo exige la base). Acepta
     * `organization_id`, `review_assignment_id` o `rubric_id` explícitos. Por defecto es un borrador.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['review_assignment_id'] ?? null)) {
                    return (string) ReviewAssignment::query()->withoutGlobalScopes()->whereKey($attributes['review_assignment_id'])->value('organization_id');
                }
                if (is_string($attributes['rubric_id'] ?? null)) {
                    return (string) Rubric::query()->withoutGlobalScopes()->whereKey($attributes['rubric_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'review_assignment_id' => fn (array $attributes): string => ReviewAssignment::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'rubric_id' => fn (array $attributes): string => Rubric::factory()
                ->published()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'submitted_at' => null,
            'revision' => 0,
        ];
    }

    /** Evaluación enviada una vez. */
    public function submitted(): static
    {
        return $this->state(['submitted_at' => now(), 'revision' => 1]);
    }
}
