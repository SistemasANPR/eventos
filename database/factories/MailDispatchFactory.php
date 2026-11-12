<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MailDispatchStatus;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MailDispatch> */
class MailDispatchFactory extends Factory
{
    protected $model = MailDispatch::class;

    /**
     * Acepta `organization_id` explícito; sin él usa la organización en contexto y, sin
     * contexto, crea una. Nace `queued` con una clave de idempotencia única.
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
            'notification_key' => 'submission.received',
            'idempotency_key' => 'submission.received:'.Str::uuid(),
            'recipient_hash' => hash_hmac('sha256', fake()->unique()->safeEmail(), 'prueba'),
            'status' => MailDispatchStatus::Queued,
            'attempts' => 0,
            'sent_at' => null,
        ];
    }
}
