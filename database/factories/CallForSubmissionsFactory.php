<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CallStatus;
use App\Enums\SubmissionType;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CallForSubmissions> */
class CallForSubmissionsFactory extends Factory
{
    protected $model = CallForSubmissions::class;

    /**
     * Convocatoria y evento comparten organización (lo exige la base). Acepta
     * `organization_id` explícito (crea el evento en ella), `event_id` explícito
     * (toma su organización) o ninguno (usa la organización en contexto y, sin
     * contexto, crea una). Nace como borrador, en español.
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
            'slug' => 'convocatoria-'.fake()->unique()->numerify('########'),
            'submission_type' => SubmissionType::Session,
            'title' => ['es' => 'Convocatoria de prueba'],
            'description' => ['es' => fake()->sentence()],
            'locales' => ['es'],
            'opens_at' => '2027-01-10 15:00:00',
            'closes_at' => '2027-02-15 23:00:00',
            'results_at' => null,
            'status' => CallStatus::Draft,
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => CallStatus::Published]);
    }
}
