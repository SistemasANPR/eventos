<?php

declare(strict_types=1);

namespace App\Support\Notifications\Mailers;

/** Resend (ADR-0009): el proveedor de R0. Usa `RESEND_API_KEY` de `config/services.php`. */
final class ResendMailer extends LaravelMailer
{
    protected function mailerName(): string
    {
        return 'resend';
    }
}
