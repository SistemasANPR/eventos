<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Datos de ejemplo para la vista previa de una plantilla (plan 008, CA-008-16). No son datos
 * reales de nadie: nombres inventados y direcciones del dominio reservado `example.test`.
 */
final class SampleMailData
{
    private const KNOWN = [
        'user_name' => 'Ana Pérez',
        'member_name' => 'Ana Pérez',
        'author_name' => 'Ana Pérez',
        'organization_name' => 'Asociación de Parques',
        'role_name' => 'Organizador',
        'event_name' => 'Congreso Parques 2027',
        'submission_title' => 'Parques vivos: ciudades que respiran',
        'submission_reference' => 'PR-2027-0042',
        'expires_in_minutes' => '30',
        'occurred_at' => '2027-01-15 14:30',
        'device' => 'Chrome en Windows',
    ];

    /** @return array<string, string> valor de ejemplo por cada variable del descriptor */
    public static function for(NotificationDescriptor $descriptor): array
    {
        $values = [];

        foreach ($descriptor->variables as $variable) {
            $values[$variable] = str_ends_with($variable, '_url')
                ? 'https://example.test/ejemplo'
                : self::KNOWN[$variable] ?? 'Ejemplo';
        }

        return $values;
    }
}
