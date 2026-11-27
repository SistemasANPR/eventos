<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ActivityContact;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ActivityContact> */
class ActivityContactFactory extends Factory
{
    protected $model = ActivityContact::class;

    /**
     * Responsable, actividad y evento comparten organización (lo exige la base). Acepta `organization_id`,
     * `event_id` o `activity_id` explícitos; sin ellos usa la organización en contexto (o crea una), un
     * evento nuevo y una actividad de ese evento.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['activity_id'] ?? null)) {
                    return (string) EventActivity::query()->withoutGlobalScopes()->whereKey($attributes['activity_id'])->value('organization_id');
                }

                if (is_string($attributes['event_id'] ?? null)) {
                    return (string) Event::query()->withoutGlobalScopes()->whereKey($attributes['event_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'event_id' => function (array $attributes): string {
                if (is_string($attributes['activity_id'] ?? null)) {
                    return (string) EventActivity::query()->withoutGlobalScopes()->whereKey($attributes['activity_id'])->value('event_id');
                }

                return Event::factory()->create(['organization_id' => $attributes['organization_id']])->id;
            },
            'activity_id' => fn (array $attributes): string => EventActivity::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'name' => fake()->name(),
            'phone' => null,
            'email' => fake()->unique()->safeEmail(),
            'position' => 1,
            'is_public' => false,
        ];
    }
}
