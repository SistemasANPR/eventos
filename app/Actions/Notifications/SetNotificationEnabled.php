<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Exceptions\Domain\CriticalNotificationCannotBeDisabled;
use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Activa o desactiva una notificación NO crítica de la organización (plan 008, H-008-5,
 * CA-008-21 y CA-008-22). Una crítica no se desactiva por ninguna vía: esta Action lo
 * rechaza aunque la interfaz no lo ofrezca, y el envío tampoco respeta una marca así.
 * Solo se audita un cambio real del estado.
 *
 * @throws AuthorizationException si quien cambia no tiene `mail-templates.manage` en la organización
 * @throws CriticalNotificationCannotBeDisabled si se intenta desactivar una crítica
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class SetNotificationEnabled
{
    use AuthorizesInOrganization;

    public function __construct(private readonly NotificationCatalog $catalog) {}

    public function handle(User $actor, Organization $organization, string $key, bool $enabled): NotificationSetting
    {
        return CurrentOrganization::run($organization, function () use ($actor, $organization, $key, $enabled): NotificationSetting {
            $this->authorizeActor($actor, 'toggle', MailTemplate::class);

            $descriptor = $this->catalog->get($key);

            if (! $enabled && $descriptor->critical) {
                throw new CriticalNotificationCannotBeDisabled($key);
            }

            return DB::transaction(function () use ($actor, $organization, $key, $enabled): NotificationSetting {
                $setting = NotificationSetting::query()->where('notification_key', $key)->lockForUpdate()->first();
                $wasEnabled = $setting instanceof NotificationSetting ? $setting->is_enabled : true;

                $setting ??= (new NotificationSetting)->forceFill(['notification_key' => $key]);
                $setting->forceFill(['is_enabled' => $enabled, 'updated_by' => $actor->id])->save();

                if ($wasEnabled !== $enabled) {
                    $event = $enabled ? 'notification_enabled' : 'notification_disabled';

                    activity()
                        ->causedBy($actor)
                        ->performedOn($setting)
                        ->event($event)
                        ->withProperties(['organization_id' => $organization->id, 'notification_key' => $key])
                        ->log($event);
                }

                return $setting;
            });
        });
    }
}
