<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/**
 * La sala ya está ocupada a esa hora por otra actividad del evento (plan 031, CA-031-10). El cambio
 * no se escribe; el mensaje nombra la actividad que la ocupa para que quien programa decida qué mover.
 */
final class ActivityRoomConflict extends DomainException
{
    public const CODE = 'ACTIVITY_ROOM_CONFLICT';

    public function __construct(public readonly string $conflictingTitle)
    {
        parent::__construct(__('program.activities.room_conflict', ['title' => $conflictingTitle]).' ('.self::CODE.')');
    }
}
