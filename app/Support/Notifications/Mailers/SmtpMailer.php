<?php

declare(strict_types=1);

namespace App\Support\Notifications\Mailers;

/** SMTP: en local entrega a Mailpit (http://localhost:8025) sin enviar nada fuera. */
final class SmtpMailer extends LaravelMailer
{
    protected function mailerName(): string
    {
        return 'smtp';
    }
}
