<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventLanding;
use App\Models\LandingImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LandingImage> */
class LandingImageFactory extends Factory
{
    protected $model = LandingImage::class;

    /**
     * Imagen, landing y evento comparten organización (lo exige la base). Acepta `organization_id`,
     * `event_id` o `event_landing_id` explícitos; sin ellos crea una landing nueva en la organización en
     * contexto. No escribe el archivo: la ruta es solo la de un archivo generado.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_landing_id' => fn (array $attributes): string => EventLanding::factory()->create(array_filter([
                'organization_id' => is_string($attributes['organization_id'] ?? null) ? $attributes['organization_id'] : null,
                'event_id' => is_string($attributes['event_id'] ?? null) ? $attributes['event_id'] : null,
            ]))->id,
            'mime' => 'image/png',
            'size_bytes' => 1024,
            'width' => 1200,
            'height' => 630,
        ];
    }

    /** Organización, evento y ruta salen de la landing de la imagen, que es la fuente única. */
    public function configure(): static
    {
        return $this->afterMaking(function (LandingImage $image): void {
            $landing = EventLanding::query()->withoutGlobalScopes()->findOrFail($image->event_landing_id);

            $image->organization_id ??= $landing->organization_id;
            $image->event_id ??= $landing->event_id;
            $image->path ??= "organizations/{$landing->organization_id}/events/{$landing->event_id}/media/landing/".Str::uuid().'.png';
        });
    }
}
