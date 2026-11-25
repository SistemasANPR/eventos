<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DecisionBatch> */
class DecisionBatchFactory extends Factory
{
    protected $model = DecisionBatch::class;

    /**
     * El lote y su evento comparten organización. Acepta `organization_id` explícito, `event_id`
     * explícito (toma su organización) o ninguno (usa la organización en contexto y, sin contexto,
     * crea una).
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
            'actor_user_id' => fn (): string => User::factory()->create()->id,
            'filter' => ['mode' => 'selection', 'to' => 'accepted'],
            'total' => 1,
            'created_at' => now(),
        ];
    }
}
