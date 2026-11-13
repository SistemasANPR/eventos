<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\Submission;
use App\Models\SubmissionType;
use App\Models\Track;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Submission> */
class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /**
     * Propuesta, evento, convocatoria, tipo, formulario, eje y aviso comparten
     * organización y evento (lo exige la base para la organización). Acepta
     * `organization_id` explícito (crea todo en ella), `event_id` explícito (toma su
     * organización) o ninguno (usa la organización en contexto y, sin contexto, crea
     * una). Nace `submitted`, de origen `call`.
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
            'origin' => SubmissionOrigin::Call,
            'call_for_submissions_id' => fn (array $attributes): string => CallForSubmissions::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'submission_type_id' => function (array $attributes): string {
                if (is_string($attributes['call_for_submissions_id'] ?? null)) {
                    return (string) CallForSubmissions::query()->withoutGlobalScopes()
                        ->whereKey($attributes['call_for_submissions_id'])->value('submission_type_id');
                }

                return SubmissionType::factory()
                    ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                    ->id;
            },
            'registered_by_user_id' => null,
            'authorization_declared_at' => null,
            'form_definition_id' => fn (array $attributes): string => FormDefinition::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'form_version' => 1,
            'form_snapshot' => ['fields' => []],
            'track_id' => fn (array $attributes): string => Track::factory()
                ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                ->id,
            'title' => fake()->sentence(4),
            'answers' => [],
            'status' => SubmissionStatus::Submitted,
            'locale' => 'es',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random(20)),
            'privacy_notice_version_id' => fn (array $attributes): ?string => ($attributes['origin'] ?? null) === SubmissionOrigin::Invited
                ? null
                : PrivacyNotice::factory()
                    ->create(['organization_id' => $attributes['organization_id'], 'event_id' => $attributes['event_id']])
                    ->id,
            'consented_at' => now(),
            'third_parties_authorized_at' => null,
            'submitted_at' => now(),
            'withdrawn_at' => null,
            'version' => 1,
        ];
    }

    /** Conferencista invitado: sin convocatoria ni consentimiento, con quien lo registró (plan 018). */
    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'origin' => SubmissionOrigin::Invited,
            'call_for_submissions_id' => null,
            'idempotency_key' => null,
            'payload_hash' => null,
            'privacy_notice_version_id' => null,
            'consented_at' => null,
            'registered_by_user_id' => User::factory()->create()->id,
            'authorization_declared_at' => now(),
            'status' => SubmissionStatus::Accepted,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state([
            'status' => SubmissionStatus::Withdrawn,
            'withdrawn_at' => now(),
        ]);
    }
}
