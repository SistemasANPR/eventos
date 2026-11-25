<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetentionCategory;
use App\Models\Organization;
use App\Models\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RetentionPolicy> */
class RetentionPolicyFactory extends Factory
{
    protected $model = RetentionPolicy::class;

    /** @var array<string, int> categorías ya entregadas por organización */
    private static array $issued = [];

    /**
     * Acepta `organization_id` explícito; sin él usa la organización en contexto y, sin contexto, crea una.
     * La categoría es única por organización: por defecto toma la siguiente libre (hasta cinco por organización).
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
            // La siguiente categoría de la organización (hay cinco; pasar de ahí viola la unicidad). Un contador
            // propio, porque `count(n)->create()` evalúa todas las definiciones antes de guardar ninguna.
            'category' => function (array $attributes): RetentionCategory {
                $next = self::$issued[$attributes['organization_id']] ?? 0;
                self::$issued[$attributes['organization_id']] = $next + 1;

                return RetentionCategory::cases()[$next % count(RetentionCategory::cases())];
            },
            'retention_days' => 365,
        ];
    }
}
