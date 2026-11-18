<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventActivity;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventActivity> */
class EventActivityFactory extends Factory
{
    protected $model = EventActivity::class;

    /**
     * Actividad y evento comparten organización (lo exige la base). Acepta `organization_id` o
     * `event_id` explícitos; sin ellos usa la organización en contexto (o crea una) y un evento
     * nuevo. Nace programada, publicada y sin propuesta de origen.
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
            'submission_id' => null,
            'title' => fake()->unique()->sentence(3),
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '10:00:00',
            'ends_at' => '11:00:00',
            'is_published' => true,
        ];
    }

    /** Fecha y hora asignadas (el estado por defecto, explícito para quien lo lea). */
    public function scheduled(string $startsOn = '2027-03-10', string $startsAt = '10:00:00', string $endsAt = '11:00:00'): static
    {
        return $this->state(fn (): array => [
            'starts_on' => $startsOn,
            'ends_on' => $startsOn,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    /** Como las que nacen de una propuesta: sin fecha ni hora (DA-4). */
    public function unscheduled(): static
    {
        return $this->state(fn (): array => [
            'starts_on' => null,
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }
}
