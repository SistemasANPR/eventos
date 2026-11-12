<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use LogicException;

/**
 * Quien dispara la notificación no entregó los valores que su plantilla necesita, o
 * entregó uno inválido (plan 008, H-008-2). Es un error de programación del módulo
 * que envía, no de la persona usuaria: se propaga, no se envía un correo a medias.
 */
final class MailTemplateContextInvalid extends LogicException
{
    public const CODE = 'MAIL_TEMPLATE_CONTEXT_INVALID';

    public static function missingVariable(string $key, string $variable): self
    {
        return new self("La plantilla de {$key} usa la variable {$variable} y no se entregó su valor.");
    }

    public static function invalidUrl(string $key, string $variable): self
    {
        return new self("La variable {$variable} de {$key} debe ser una dirección http o https.");
    }
}
