<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Ficha de una notificación del catálogo (plan 008, ADR-0009). Los textos
 * descriptivos viven en `lang/*\/notifications.php` bajo la clave de la notificación;
 * la plantilla predeterminada, en `lang/*\/mail_templates.php`.
 */
final readonly class NotificationDescriptor
{
    /**
     * @param  list<string>  $variables  lista blanca de variables `{{ variable }}` de la plantilla
     * @param  list<string>  $locales  idiomas con plantilla predeterminada
     * @param  int|null  $recipientLimit  envíos máximos por destinatario y hora; `null` = sin límite
     */
    public function __construct(
        public string $key,
        public array $variables,
        public bool $critical,
        public array $locales = ['es', 'en'],
        public ?int $recipientLimit = null,
    ) {}

    public function name(): string
    {
        return $this->text('name');
    }

    public function description(): string
    {
        return $this->text('description');
    }

    public function trigger(): string
    {
        return $this->text('trigger');
    }

    public function recipient(): string
    {
        return $this->text('recipient');
    }

    private function text(string $part): string
    {
        return __("notifications.{$this->key}.{$part}");
    }
}
