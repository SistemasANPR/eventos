<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrivacyNotice> */
class PrivacyNoticeFactory extends Factory
{
    protected $model = PrivacyNotice::class;

    /**
     * Aviso y evento comparten organización (lo exige la base). Acepta
     * `organization_id` explícito (crea el evento en ella), `event_id`
     * explícito (toma su organización) o ninguno (usa la organización en
     * contexto y, sin contexto, crea una). Nace como borrador de la versión 1.
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
            'version' => 1,
            'body' => ['es' => '<p>Aviso de privacidad de prueba.</p>', 'en' => '<p>Test privacy notice.</p>'],
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now(),
            'published_by' => User::factory()->create()->id,
        ]);
    }
}
