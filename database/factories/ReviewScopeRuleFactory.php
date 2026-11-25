<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReviewAssignmentScope;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ReviewScopeRule;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReviewScopeRule> */
class ReviewScopeRuleFactory extends Factory
{
    protected $model = ReviewScopeRule::class;

    /**
     * Por defecto, una regla por eje. Eje (o convocatoria), evento y evaluador comparten la organización
     * (lo exige la base); el evaluador y quien creó la regla se unen a ella como integrantes activos. No crea el
     * `event_assignments` del evaluador: eso lo exige la Action, no la base.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => CurrentOrganization::has()
                ? CurrentOrganization::id()
                : Organization::factory()->create()->id,
            'event_id' => fn (array $attributes): string => Event::factory()->create(['organization_id' => $attributes['organization_id']])->id,
            'reviewer_id' => fn (array $attributes): string => $this->member($attributes['organization_id']),
            'scope_type' => ReviewAssignmentScope::Track,
            'track_id' => fn (array $attributes): ?string => ($attributes['scope_type'] ?? null) === ReviewAssignmentScope::Call
                ? null
                : Track::factory()->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])->id,
            'call_id' => fn (array $attributes): ?string => ($attributes['scope_type'] ?? null) === ReviewAssignmentScope::Call
                ? CallForSubmissions::factory()->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])->id
                : null,
            'created_by' => fn (array $attributes): string => $this->member($attributes['organization_id']),
        ];
    }

    /** Una regla por convocatoria. */
    public function forCall(): static
    {
        return $this->state(['scope_type' => ReviewAssignmentScope::Call]);
    }

    private function member(string $organizationId): string
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organizationId, ['status' => 'active']);

        return $user->id;
    }
}
