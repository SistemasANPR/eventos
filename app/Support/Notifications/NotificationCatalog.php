<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use InvalidArgumentException;

/**
 * Registro central en código de todo correo que dispara el sistema (plan 008, ADR-0009).
 * Una notificación que no está aquí no existe: añadir una exige su descriptor, su
 * plantilla predeterminada es/en y su prueba (guardianas en `tests/Feature/Notifications`).
 */
final class NotificationCatalog
{
    /** @var array<string, NotificationDescriptor>|null */
    private ?array $descriptors = null;

    /** @return array<string, NotificationDescriptor> */
    public function all(): array
    {
        return $this->descriptors ??= $this->build();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @throws InvalidArgumentException si la clave no está registrada */
    public function get(string $key): NotificationDescriptor
    {
        return $this->all()[$key]
            ?? throw new InvalidArgumentException("Notificación no registrada en el catálogo: {$key}");
    }

    /** @return array<string, NotificationDescriptor> */
    private function build(): array
    {
        $descriptors = [
            new NotificationDescriptor('auth.password_reset', ['user_name', 'reset_url', 'expires_in_minutes'], critical: true),
            new NotificationDescriptor('auth.new_device_login', ['user_name', 'occurred_at', 'device'], critical: true),
            new NotificationDescriptor('team.set_password', ['member_name', 'organization_name', 'role_name', 'set_password_url'], critical: true),
            new NotificationDescriptor('team.membership_added', ['member_name', 'organization_name', 'role_name', 'login_url'], critical: true),
            new NotificationDescriptor('submission.received', ['author_name', 'submission_title', 'submission_reference', 'event_name'], critical: true, recipientLimit: 5),
            new NotificationDescriptor('submission.access_link', ['author_name', 'event_name', 'access_url', 'expires_in_minutes'], critical: true, recipientLimit: 5),
            new NotificationDescriptor('submission.new_for_organizers', ['author_name', 'submission_title', 'event_name', 'panel_url'], critical: false),
            new NotificationDescriptor('submission.updated', ['author_name', 'submission_title', 'event_name', 'panel_url'], critical: false),
            new NotificationDescriptor('submission.withdrawn', ['author_name', 'submission_title', 'event_name', 'panel_url'], critical: false),
        ];

        $indexed = [];

        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->key] = $descriptor;
        }

        return $indexed;
    }
}
