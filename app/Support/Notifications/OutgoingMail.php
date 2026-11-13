<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Correo ya renderizado y listo para entregar al proveedor. `fromName` y `replyTo` vienen de
 * la plantilla de la organización; la dirección del remitente es siempre la del dominio
 * verificado (plan 008, DA-7), nunca la que escriba una organización.
 */
final readonly class OutgoingMail
{
    public function __construct(
        public MailRecipient $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?string $fromName = null,
        public ?string $replyTo = null,
    ) {}
}
