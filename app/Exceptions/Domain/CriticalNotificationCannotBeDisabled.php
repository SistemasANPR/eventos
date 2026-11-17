<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/**
 * Se intentó desactivar una notificación crítica (restablecer contraseña, invitaciones,
 * acuse, enlace de acceso): el sistema depende de ellas y no se pueden apagar (plan 008, CA-008-22).
 */
final class CriticalNotificationCannotBeDisabled extends InvalidArgumentException
{
    public const CODE = 'CRITICAL_NOTIFICATION_CANNOT_BE_DISABLED';

    public function __construct(string $key)
    {
        parent::__construct("La notificación {$key} es crítica y no se puede desactivar (código ".self::CODE.').');
    }
}
