<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Notifications\Mailers\ResendMailer;
use App\Support\Notifications\Mailers\SmtpMailer;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\TransactionalMailer;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/** Enlaza el proveedor de correo según `config/notifications.php` (plan 008, ADR-0009). */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationCatalog::class);

        $this->app->bind(TransactionalMailer::class, fn (): TransactionalMailer => match (config('notifications.driver')) {
            'resend' => new ResendMailer,
            'smtp' => new SmtpMailer,
            default => throw new InvalidArgumentException('NOTIFICATIONS_MAILER desconocido: '.(string) config('notifications.driver')),
        });
    }
}
