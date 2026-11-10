<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Acepta `organization_id` explícito; si no se da, usa la organización en
     * contexto y, sin contexto, crea una nueva. Nace en borrador.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => CurrentOrganization::has()
                ? CurrentOrganization::id()
                : Organization::factory()->create()->id,
            'name' => ['es' => 'Congreso '.fake()->word(), 'en' => 'Congress '.fake()->word()],
            'description' => ['es' => fake()->sentence(), 'en' => fake()->sentence()],
            'slug' => 'evento-'.fake()->unique()->numerify('########'),
            'type' => EventType::Congress,
            'starts_at' => '2027-03-10 15:00:00',
            'ends_at' => '2027-03-12 23:00:00',
            'timezone' => 'America/Mexico_City',
            'venue_name' => fake()->company(),
            'venue_address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'locales' => ['es', 'en'],
            'default_locale' => 'es',
            'currency' => 'MXN',
            'status' => EventStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => EventStatus::Published]);
    }

    public function archived(): static
    {
        return $this->state(['status' => EventStatus::Archived]);
    }
}
