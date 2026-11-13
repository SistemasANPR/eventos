<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubmissionParticipant> */
class SubmissionParticipantFactory extends Factory
{
    protected $model = SubmissionParticipant::class;

    /**
     * Participante, propuesta y contacto comparten organización (lo exige la base).
     * Acepta `organization_id` explícito (crea la propuesta en ella), `submission_id`
     * explícito (toma su organización) o ninguno (usa la organización en contexto y,
     * sin contexto, crea una). Nace como participante principal en la posición 1.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => function (array $attributes): string {
                if (is_string($attributes['submission_id'] ?? null)) {
                    return (string) Submission::query()->withoutGlobalScopes()->whereKey($attributes['submission_id'])->value('organization_id');
                }

                return CurrentOrganization::has()
                    ? CurrentOrganization::id()
                    : Submission::factory()->create()->organization_id;
            },
            'submission_id' => fn (array $attributes): string => Submission::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'contact_id' => fn (array $attributes): string => Contact::factory()
                ->create(['organization_id' => $attributes['organization_id']])
                ->id,
            'position' => 1,
            'is_primary' => true,
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'country' => 'MX',
            'job_title' => null,
            'company' => null,
            'bio' => null,
        ];
    }

    public function secondary(int $position = 2): static
    {
        return $this->state(['position' => $position, 'is_primary' => false]);
    }
}
