<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FormDefinitionStatus;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormDefinition> */
class FormDefinitionFactory extends Factory
{
    protected $model = FormDefinition::class;

    /**
     * Formulario y evento comparten organización (lo exige la base). Acepta
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
            'name' => 'Formulario de sesiones',
            'status' => FormDefinitionStatus::Draft,
            'fields' => [],
            'version' => 1,
            'published_at' => null,
        ];
    }
}
