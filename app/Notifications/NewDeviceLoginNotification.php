<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * `auth.new_device_login` (catálogo del plan 008, crítica): avisa de un inicio
 * de sesión desde un dispositivo no registrado. Solo lleva la fecha (UTC) y un
 * dispositivo aproximado; nunca contraseñas, tokens, IP ni enlaces (CA-001-36).
 */
final class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const CATALOG_KEY = 'auth.new_device_login';

    public function __construct(
        public readonly CarbonImmutable $occurredAt,
        public readonly string $device,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('access.new_device.subject'))
            ->greeting(__('access.new_device.greeting'))
            ->line(__('access.new_device.line'))
            ->line(__('access.new_device.when', ['date' => $this->occurredAt->utc()->format('Y-m-d H:i')]))
            ->line(__('access.new_device.device', ['device' => $this->device]))
            ->line(__('access.new_device.ignore'));
    }
}
