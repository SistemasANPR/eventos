<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use DomainException;

/** Un invitado se registra solo con un formulario publicado (plan 018, CA-018-5). */
final class InvitationFormNotPublished extends DomainException
{
    public const CODE = 'FORM_NOT_PUBLISHED';

    public function __construct()
    {
        parent::__construct('El formulario no está publicado; un invitado solo se registra con uno publicado (código '.self::CODE.').');
    }
}
