<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Resultado de renderizar una plantilla: asunto de una sola línea y cuerpo en HTML
 * y en texto plano. No lleva destinatario: lo fija solo `SendNotification` (ADR-0009).
 */
final readonly class RenderedMail
{
    public function __construct(
        public string $subject,
        public string $html,
        public string $text,
    ) {}
}
