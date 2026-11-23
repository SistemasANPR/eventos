<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewAssignment> */
class ReviewAssignmentFactory extends Factory
{
    protected $model = ReviewAssignment::class;

    /**
     * Propuesta, evento y evaluador comparten la organización de la asignación (lo exige la base). Acepta
     * `organization_id`, `event_id`, `submission_id` o `reviewer_id` explícitos; el evaluador nuevo se une a
     * la organización como integrante activo. No crea el `event_assignments` del evaluador: eso lo exige
     * la Action `AssignReviewers`, no la base.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['submission_id'] ?? null)) {
                    return (string) Submission::query()->withoutGlobalScopes()->whereKey($attributes['submission_id'])->value('organization_id');
                }
                if (is_string($attributes['event_id'] ?? null)) {
                    return (string) Event::query()->withoutGlobalScopes()->whereKey($attributes['event_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'event_id' => function (array $attributes): string {
                if (is_string($attributes['submission_id'] ?? null)) {
                    return (string) Submission::query()->withoutGlobalScopes()->whereKey($attributes['submission_id'])->value('event_id');
                }

                return Event::factory()->create(['organization_id' => $attributes['organization_id']])->id;
            },
            'submission_id' => fn (array $attributes): string => Submission::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'reviewer_id' => function (array $attributes): string {
                $user = User::factory()->create();
                $user->organizations()->attach($attributes['organization_id'], ['status' => 'active']);

                return $user->id;
            },
            'status' => ReviewAssignmentStatus::Pending,
            'assigned_by' => null,
            'assigned_at' => now(),
            'source' => ReviewAssignmentSource::Manual,
        ];
    }

    public function status(ReviewAssignmentStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
