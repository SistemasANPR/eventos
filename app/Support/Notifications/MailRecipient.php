<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * A quién se envía un correo. Lo construye el módulo que dispara la notificación con
 * datos del sistema; nunca sale de una plantilla ni de un valor de variable (ADR-0009).
 * El nombre se limpia de saltos de línea y caracteres de control para no abrir cabeceras.
 */
final readonly class MailRecipient
{
    public string $name;

    public function __construct(public string $email, string $name, public string $locale = 'es')
    {
        $this->name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));
    }
}
