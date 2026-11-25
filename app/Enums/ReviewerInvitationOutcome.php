<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Resultado de invitar a un evaluador (plan 011, H-011-8). Deliberadamente solo dos: no distingue una cuenta
 * nueva de una que ya existía en otra organización, para no revelar si el correo estaba registrado.
 */
enum ReviewerInvitationOutcome: string
{
    /** Se dio de alta en la organización (cuenta nueva o ya existente fuera de ella) y se le envió `reviewer.invitation`. */
    case Invited = 'invited';

    /** Ya era integrante de la organización: se le asignó el evento (si faltaba) y NO se envió ningún enlace (CA-011-36). */
    case AlreadyMember = 'already_member';
}
