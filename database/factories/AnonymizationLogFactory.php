<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnonymizationLog;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AnonymizationLog> */
class AnonymizationLogFactory extends Factory
{
    protected $model = AnonymizationLog::class;

    /**
     * Acepta `organization_id` explícito; sin él usa la organización en contexto y, sin contexto, crea una.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'organization_id' => fn (): string => CurrentOrganization::has()
                ? CurrentOrganization::id()
                : Organization::factory()->create()->id,
            'email_hash' => hash('sha256', fake()->unique()->safeEmail()),
            'anonymized_at' => now(),
        ];
    }
}
