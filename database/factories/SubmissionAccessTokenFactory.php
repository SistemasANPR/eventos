<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SubmissionAccessToken> */
class SubmissionAccessTokenFactory extends Factory
{
    protected $model = SubmissionAccessToken::class;

    /**
     * Token y propuesta comparten organización (lo exige la base). Acepta
     * `organization_id` explícito (crea la propuesta en ella), `submission_id`
     * explícito (toma su organización) o ninguno. Solo guarda el hash; nace vigente
     * y sin consumir.
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
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addMinutes(30),
            'consumed_at' => null,
        ];
    }

    public function consumed(): static
    {
        return $this->state(['consumed_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }
}
