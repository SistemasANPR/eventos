<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use InvalidArgumentException;

/** La persona ya inició sesión alguna vez: su invitación no está pendiente y no se reenvía. */
final class InvitationNotPending extends InvalidArgumentException
{
    public const CODE = 'INVITATION_NOT_PENDING';

    public function __construct()
    {
        parent::__construct('La persona ya activó su cuenta; no hay invitación pendiente (código '.self::CODE.').');
    }
}
