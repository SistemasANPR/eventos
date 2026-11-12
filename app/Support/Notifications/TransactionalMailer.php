<?php

declare(strict_types=1);

namespace App\Support\Notifications;

/**
 * Proveedor de correo transaccional (ADR-0009, decisión 1). Resend en R0 y SES en R1
 * son adaptadores de esta interfaz: cambiar de proveedor es configuración, no código
 * de los módulos. Una falla del proveedor lanza una excepción; nunca se traga.
 */
interface TransactionalMailer
{
    public function send(OutgoingMail $mail): void;
}
