<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * La plantilla que se usará para un envío y de dónde salió: `event`, `organization` o
 * `system` (plan 008, DA-5). El cuerpo y el asunto siguen con sus `{{ variables }}` sin resolver.
 */
final readonly class ResolvedMailTemplate
{
    public function __construct(
        public string $subject,
        public string $body,
        public ?string $fromName,
        public ?string $replyTo,
        public string $source,
    ) {}
}
