<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionStatusChange;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionStatusChange> */
class SubmissionStatusChangeFactory extends Factory
{
    protected $model = SubmissionStatusChange::class;

    /**
     * El cambio, su propuesta y el evento de la propuesta comparten organización. Acepta
     * `organization_id` explícito, `submission_id` explícito (toma su organización y evento) o ninguno
     * (usa la organización en contexto y, sin contexto, crea una).
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

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'submission_id' => fn (array $attributes): string => Submission::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'event_id' => fn (array $attributes): string => (string) Submission::query()->withoutGlobalScopes()
                ->whereKey($attributes['submission_id'])->value('event_id'),
            'from_status' => SubmissionStatus::Submitted,
            'to_status' => SubmissionStatus::UnderReview,
            'actor_user_id' => null,
            'comment' => null,
            'author_comment' => null,
            'decision_batch_id' => null,
            'created_at' => now(),
        ];
    }
}
