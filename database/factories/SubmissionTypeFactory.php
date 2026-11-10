<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionType;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionType> */
class SubmissionTypeFactory extends Factory
{
    protected $model = SubmissionType::class;

    /**
     * Tipo y evento comparten organización (lo exige la base). Acepta
     * `organization_id` o `event_id` explícitos; sin ellos usa la organización en
     * contexto (o crea una) y un evento nuevo en ella.
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
            'name' => ['es' => 'Sesión'],
            'position' => 1,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(['archived_at' => now()]);
    }
}
