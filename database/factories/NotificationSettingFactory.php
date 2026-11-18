<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationSetting> */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    /**
     * Acepta `organization_id` explícito; sin él usa la organización en contexto y, sin
     * contexto, crea una. Nace desactivada y con una clave única para no chocar con
     * `unique(organization_id, notification_key)`.
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
            'notification_key' => 'submission.updated.'.Str::lower(Str::random(8)),
            'is_enabled' => false,
        ];
    }
}
