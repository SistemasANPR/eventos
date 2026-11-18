<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Acepta `organization_id` explícito; si no se da, usa la organización en
     * contexto y, sin contexto, crea una nueva.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn (): string => CurrentOrganization::has()
                ? CurrentOrganization::id()
                : Organization::factory()->create()->id,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'country' => fake()->randomElement(['MX', 'CO', 'AR', 'ES']),
        ];
    }
}
