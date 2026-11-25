<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Lodging;
use App\Models\LodgingImage;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LodgingImage> */
class LodgingImageFactory extends Factory
{
    protected $model = LodgingImage::class;

    /**
     * Imagen, hospedaje y evento comparten organización (lo exige la base). Acepta `organization_id` o
     * `lodging_id` explícitos; sin ellos usa la organización en contexto (o crea una) y un hospedaje nuevo.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['lodging_id'] ?? null)) {
                    return (string) Lodging::query()->withoutGlobalScopes()->whereKey($attributes['lodging_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Organization::factory()->create()->id;
            },
            'lodging_id' => fn (array $attributes): string => Lodging::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'event_id' => fn (array $attributes): string => (string) Lodging::query()->withoutGlobalScopes()
                ->whereKey($attributes['lodging_id'])
                ->value('event_id'),
            'path' => fn (array $attributes): string => "organizations/{$attributes['organization_id']}/events/{$attributes['event_id']}/media/lodging_image/".Str::uuid().'.png',
            'position' => 1,
        ];
    }
}
