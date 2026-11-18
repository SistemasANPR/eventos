<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use App\Models\SpeakerCategory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SpeakerCategory> */
class SpeakerCategoryFactory extends Factory
{
    protected $model = SpeakerCategory::class;

    /**
     * Categoría y evento comparten organización (lo exige la base). Acepta `organization_id` o
     * `event_id` explícitos; sin ellos usa la organización en contexto (o crea una) y un evento nuevo.
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
            'name' => fake()->unique()->words(2, true),
            'position' => 1,
        ];
    }
}
