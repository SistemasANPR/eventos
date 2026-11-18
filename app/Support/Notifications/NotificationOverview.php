<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;

/**
 * El catálogo con el estado de cada notificación para una organización (plan 008, H-008-3):
 * `disabled` si la desactivó (solo las no críticas pueden estarlo), `customized` si tiene una
 * plantilla propia en algún idioma y `default` en otro caso. Son dos consultas, sin N+1.
 *
 * Una crítica nunca figura como desactivada: aunque hubiera una marca así en la base, el
 * envío la ignora (CA-008-22), y la pantalla no debe decir lo contrario.
 */
final class NotificationOverview
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * @return array<string, array{key: string, name: string, description: string, trigger: string, recipient: string, critical: bool, enabled: bool, locales: list<string>, customized_locales: list<string>, status: string}>
     */
    public function forOrganization(Organization $organization): array
    {
        [$customized, $disabled] = CurrentOrganization::run($organization, fn (): array => [
            MailTemplate::query()
                ->whereNull('event_id')
                ->get(['notification_key', 'locale'])
                ->groupBy('notification_key')
                ->map(fn ($rows): array => $rows->pluck('locale')->unique()->sort()->values()->all())
                ->all(),
            NotificationSetting::query()
                ->where('is_enabled', false)
                ->pluck('notification_key')
                ->all(),
        ]);

        $rows = [];

        foreach ($this->catalog->all() as $key => $descriptor) {
            $locales = $customized[$key] ?? [];
            $enabled = $descriptor->critical || ! in_array($key, $disabled, true);

            $rows[$key] = [
                'key' => $key,
                'name' => $descriptor->name(),
                'description' => $descriptor->description(),
                'trigger' => $descriptor->trigger(),
                'recipient' => $descriptor->recipient(),
                'critical' => $descriptor->critical,
                'enabled' => $enabled,
                'locales' => $descriptor->locales,
                'customized_locales' => $locales,
                'status' => ! $enabled ? 'disabled' : ($locales !== [] ? 'customized' : 'default'),
            ];
        }

        return $rows;
    }
}
