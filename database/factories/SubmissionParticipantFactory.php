<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Program\PersonName;
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
            // Si una prueba fija solo `full_name`, las partes salen de él (un nombre de una palabra queda
            // como dato antiguo, sin partes); si no, `full_name` se calcula de las partes.
            'first_name' => fn (array $attributes): ?string => is_string($attributes['full_name'] ?? null)
                ? self::partOf($attributes['full_name'], 'first')
                : fake()->firstName(),
            'last_name' => fn (array $attributes): ?string => is_string($attributes['full_name'] ?? null)
                ? self::partOf($attributes['full_name'], 'last')
                : fake()->lastName(),
            'full_name' => fn (array $attributes): string => $attributes['first_name'].' '.$attributes['last_name'],
            'email' => fake()->unique()->safeEmail(),
            'country' => 'MX',
            'job_title' => null,
            'company' => null,
            'bio' => null,
        ];
    }

    private static function partOf(string $fullName, string $part): ?string
    {
        $parts = PersonName::split($fullName);

        return $parts['last'] === '' ? null : $parts[$part];
    }

    public function secondary(int $position = 2): static
    {
        return $this->state(['position' => $position, 'is_primary' => false]);
    }
}
