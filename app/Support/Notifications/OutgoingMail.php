<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/** Correo ya renderizado y listo para entregar al proveedor. */
final readonly class OutgoingMail
{
    public function __construct(
        public MailRecipient $to,
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
