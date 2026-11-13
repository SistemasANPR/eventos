<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Models\MailTemplate;
use App\Models\Organization;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\ResolvedMailTemplate;
use App\Support\Tenancy\CurrentOrganization;
use InvalidArgumentException;

/**
 * Elige la plantilla de un envío: evento, luego organización, luego la predeterminada del
 * sistema (plan 008, DA-5 y CA-008-20). Solo lee plantillas de la organización indicada: la
 * consulta pasa por el scope de organización, así que no hay forma de leer las de otra.
 *
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class ResolveMailTemplate
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    public function handle(string $key, string $locale, Organization $organization, ?string $eventId = null): ResolvedMailTemplate
    {
        $this->catalog->get($key);

        $custom = CurrentOrganization::run($organization, function () use ($key, $locale, $eventId): ?ResolvedMailTemplate {
            $rows = MailTemplate::query()
                ->where('notification_key', $key)
                ->where('locale', $locale)
                ->where(fn ($query) => $eventId === null
                    ? $query->whereNull('event_id')
                    : $query->whereNull('event_id')->orWhere('event_id', $eventId))
                ->get();

            $row = ($eventId !== null ? $rows->firstWhere('event_id', $eventId) : null) ?? $rows->firstWhere('event_id', null);

            return $row === null ? null : new ResolvedMailTemplate(
                $row->subject,
                $row->body,
                $row->from_name,
                $row->reply_to,
                $row->event_id !== null ? 'event' : 'organization',
            );
        });

        return $custom ?? new ResolvedMailTemplate(
            (string) trans("mail_templates.{$key}.subject", [], $locale),
            (string) trans("mail_templates.{$key}.body", [], $locale),
            null,
            null,
            'system',
        );
    }
}
