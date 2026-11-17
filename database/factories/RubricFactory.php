<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RubricStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Rubric;
use App\Models\SubmissionType;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Rubric> */
class RubricFactory extends Factory
{
    protected $model = Rubric::class;

    /**
     * Evento y tipo comparten la organización de la rúbrica (lo exige la base).
     * Acepta `organization_id`, `event_id` o `submission_type_id` explícitos.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['event_id'] ?? null)) {
                    return (string) Event::query()->withoutGlobalScopes()->whereKey($attributes['event_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'event_id' => fn (array $attributes): string => Event::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'submission_type_id' => fn (array $attributes): string => SubmissionType::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'version' => 1,
            'status' => RubricStatus::Draft,
            'blind_review' => false,
            'min_reviewers' => 2,
            'discrepancy_threshold' => 2,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => RubricStatus::Published]);
    }
}
