<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Elimina la personalización de una notificación en un idioma y vuelve a la predeterminada
 * del sistema (plan 008, H-008-4, CA-008-17). Devuelve `true` si había algo que restaurar.
 * Solo toca la fila de nivel organización de ese idioma, y la acción queda auditada.
 *
 * @throws AuthorizationException si quien restaura no tiene `mail-templates.manage` en la organización
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class RestoreMailTemplate
{
    use AuthorizesInOrganization;

    public function __construct(private readonly NotificationCatalog $catalog) {}

    public function handle(User $actor, Organization $organization, string $key, string $locale): bool
    {
        return CurrentOrganization::run($organization, function () use ($actor, $organization, $key, $locale): bool {
            $this->authorizeActor($actor, 'restore', MailTemplate::class);

            $this->catalog->get($key);

            return DB::transaction(function () use ($actor, $organization, $key, $locale): bool {
                $template = MailTemplate::query()
                    ->where('notification_key', $key)
                    ->where('locale', $locale)
                    ->whereNull('event_id')
                    ->lockForUpdate()
                    ->first();

                if ($template === null) {
                    return false;
                }

                $template->delete();

                activity()
                    ->causedBy($actor)
                    ->event('mail_template_restored')
                    ->withProperties([
                        'organization_id' => $organization->id,
                        'notification_key' => $key,
                        'locale' => $locale,
                    ])
                    ->log('mail_template_restored');

                return true;
            });
        });
    }
}
