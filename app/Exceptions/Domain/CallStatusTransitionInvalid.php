<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\CallStatus;
use Illuminate\Validation\ValidationException;

/**
 * El cambio de estado manual no es una transición permitida (plan 004, H-004-4):
 * un borrador no se pausa, cierra ni reabre, y no se repite el estado actual.
 * Es una `ValidationException` para que el panel la muestre como error de la acción.
 */
final class CallStatusTransitionInvalid extends ValidationException
{
    public const CODE = 'CALL_STATUS_TRANSITION_INVALID';

    public static function from(CallStatus $current, CallStatus $target): self
    {
        /** @var self $exception */
        $exception = self::withMessages([
            'status' => __('calls.status_transition_invalid', ['from' => $current->getLabel(), 'to' => $target->getLabel()]).' ('.self::CODE.')',
        ]);

        return $exception;
    }
}
