<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventAssignmentRole;
use App\Models\Event;
use App\Models\EventAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventAssignment> */
class EventAssignmentFactory extends Factory
{
    protected $model = EventAssignment::class;

    /**
     * Evento y usuario comparten la organización de la asignación (lo exige la
     * base). Acepta `organization_id`, `event_id` o `user_id` explícitos; el
     * usuario nuevo se une a la organización como integrante activo.
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
            'user_id' => function (array $attributes): string {
                $user = User::factory()->create();
                $user->organizations()->attach($attributes['organization_id'], ['status' => 'active']);

                return $user->id;
            },
            'role' => EventAssignmentRole::Reviewer,
        ];
    }

    public function coordinator(): static
    {
        return $this->state(['role' => EventAssignmentRole::Coordinator]);
    }
}
