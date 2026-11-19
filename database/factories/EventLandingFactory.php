<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Support\Landings\LandingTemplate;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventLanding> */
class EventLandingFactory extends Factory
{
    protected $model = EventLanding::class;

    /**
     * Landing y evento comparten organización (lo exige la base). Acepta `organization_id` o `event_id`
     * explícitos; sin ellos usa la organización en contexto (o crea una) y un evento nuevo. Nace con la
     * plantilla en el borrador y sin versión publicada.
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
            'draft_blocks' => fn (array $attributes): array => LandingTemplate::blocks(
                Event::query()->withoutGlobalScopes()->findOrFail($attributes['event_id']),
            ),
            'published_blocks' => null,
            'version' => 1,
        ];
    }

    /** Publicada: la versión publicada es una copia del borrador. */
    public function published(): static
    {
        return $this->afterMaking(function (EventLanding $landing): void {
            $landing->published_blocks = $landing->draft_blocks;
            $landing->published_at = now();
        });
    }
}
