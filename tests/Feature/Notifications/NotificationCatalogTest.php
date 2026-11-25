<?php

declare(strict_types=1);

use App\Support\Notifications\NotificationCatalog;
use Illuminate\Notifications\Notification;

/*
 * Catálogo de notificaciones (plan 008, H-008-1, ADR-0009). Es la guardiana de la
 * regla «toda notificación se registra con su plantilla predeterminada es/en».
 */

const R0_NOTIFICATION_KEYS = [
    'auth.password_reset',
    'auth.new_device_login',
    'auth.two_factor_enabled',
    'auth.two_factor_reset',
    'team.set_password',
    'team.membership_added',
    'submission.received',
    'submission.access_link',
    'submission.new_for_organizers',
    'submission.updated',
    'submission.withdrawn',
    'submission.withdrawn_author',
    'submission.accepted',
    'submission.rejected',
    'submission.waitlisted',
    'call.closing_reminder',
    'reviewer.assignment',
    'reviewer.invitation',
];

const CRITICAL_NOTIFICATION_KEYS = [
    'submission.accepted',
    'submission.rejected',
    'submission.waitlisted',
    'auth.password_reset',
    'auth.new_device_login',
    'auth.two_factor_enabled',
    'auth.two_factor_reset',
    'team.set_password',
    'team.membership_added',
    'submission.received',
    'submission.access_link',
    'reviewer.assignment',
    'reviewer.invitation',
];

it('CA-008-1: the catalog holds every R0 key with all its metadata', function () {
    $catalog = app(NotificationCatalog::class);

    expect(array_keys($catalog->all()))->toEqualCanonicalizing(R0_NOTIFICATION_KEYS);

    foreach ($catalog->all() as $key => $descriptor) {
        expect($descriptor->key)->toBe($key);
        expect($descriptor->locales)->toContain('es', 'en');
        expect($descriptor->variables)->not->toBeEmpty();

        // Un texto sin traducir se vería como la clave cruda (`notifications.<clave>.<parte>`).
        foreach (['name', 'description', 'trigger', 'recipient'] as $part) {
            $text = $descriptor->{$part}();

            expect($text)->not->toBe('')->and($text)->not->toStartWith('notifications.');
        }
    }
});

it('CA-008-1: only the documented notifications are critical', function () {
    $critical = collect(app(NotificationCatalog::class)->all())
        ->filter(fn ($descriptor) => $descriptor->critical)
        ->keys()
        ->all();

    expect($critical)->toEqualCanonicalizing(CRITICAL_NOTIFICATION_KEYS);
});

it('CA-012-17: the result mails are critical, have es and en defaults, cannot be turned off and carry no access link', function () {
    $catalog = app(NotificationCatalog::class);

    foreach (['submission.accepted', 'submission.rejected', 'submission.waitlisted'] as $key) {
        $descriptor = $catalog->get($key);

        expect($descriptor->critical)->toBeTrue()
            ->and($descriptor->locales)->toContain('es', 'en')
            ->and($descriptor->variables)->not->toContain('access_link', 'access_url')
            ->and($descriptor->variables)->toContain('author_comment', 'request_link_url');

        foreach (['es', 'en'] as $locale) {
            foreach (['subject', 'body'] as $part) {
                $text = trans("mail_templates.{$key}.{$part}", [], $locale);

                expect($text)->toBeString()
                    ->and($text)->not->toBe('')
                    ->and($text)->not->toBe("mail_templates.{$key}.{$part}")
                    ->and($text)->not->toContain('access_link')
                    ->and($text)->not->toContain('access_url');
            }
        }
    }
});

it('CA-012-23: the closing reminder is registered as non critical with an optional edit hint', function () {
    $descriptor = app(NotificationCatalog::class)->get('call.closing_reminder');

    expect($descriptor->critical)->toBeFalse()
        ->and($descriptor->variables)->toContain('edit_hint', 'closing_date', 'request_link_url')
        ->and($descriptor->variables)->not->toContain('access_link', 'access_url');
});

it('CA-008-1: recipient limits follow DA-8', function () {
    $catalog = app(NotificationCatalog::class);

    expect($catalog->get('submission.received')->recipientLimit)->toBe(5)
        ->and($catalog->get('submission.access_link')->recipientLimit)->toBe(5)
        ->and($catalog->get('auth.password_reset')->recipientLimit)->toBeNull();
});

it('rejects a key that is not in the catalog instead of returning null', function () {
    app(NotificationCatalog::class)->get('made.up_key');
})->throws(InvalidArgumentException::class);

it('CA-008-2: every notification class is registered in the catalog', function () {
    $catalog = app(NotificationCatalog::class);
    $unregistered = [];

    foreach (glob(app_path('Notifications/*.php')) ?: [] as $file) {
        $class = 'App\\Notifications\\'.basename($file, '.php');

        if (! is_subclass_of($class, Notification::class)) {
            continue;
        }

        $key = defined("{$class}::CATALOG_KEY") ? constant("{$class}::CATALOG_KEY") : null;

        if (! is_string($key) || ! $catalog->has($key)) {
            $unregistered[] = $class;
        }
    }

    expect($unregistered)->toBe([], 'Notificaciones sin registrar en el catálogo: '.implode(', ', $unregistered));
});

it('CA-008-3: every catalog key has a default template in es and en', function () {
    $missing = [];

    foreach (app(NotificationCatalog::class)->all() as $key => $descriptor) {
        foreach (['es', 'en'] as $locale) {
            foreach (['subject', 'body'] as $part) {
                $text = trans("mail_templates.{$key}.{$part}", [], $locale);

                if (! is_string($text) || $text === '' || $text === "mail_templates.{$key}.{$part}") {
                    $missing[] = "{$key} ({$locale}, {$part})";
                }
            }
        }
    }

    expect($missing)->toBe([], 'Plantilla predeterminada faltante: '.implode('; ', $missing));
});

it('default templates only use variables from the descriptor whitelist', function () {
    $offending = [];

    foreach (app(NotificationCatalog::class)->all() as $key => $descriptor) {
        foreach (['es', 'en'] as $locale) {
            foreach (['subject', 'body'] as $part) {
                $text = (string) trans("mail_templates.{$key}.{$part}", [], $locale);
                preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $found);

                foreach (array_diff($found[1], $descriptor->variables) as $variable) {
                    $offending[] = "{$key} ({$locale}): {$variable}";
                }
            }
        }
    }

    expect($offending)->toBe([], 'Variables fuera de la lista blanca: '.implode('; ', $offending));
});
