<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Notifications\OutgoingMail;
use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * Envoltorio de Laravel para un correo ya renderizado. No se pone en cola (el job ya
 * corre en segundo plano) y solo lleva al destinatario que fijó `SendNotification`.
 *
 * Se arma con la API fluida (no con `envelope()`) para que `Mail::fake()` vea el
 * destinatario y el asunto igual que un envío real. El remitente conserva siempre la
 * dirección verificada de `config('mail.from')`; la organización solo cambia el nombre
 * visible y el responder-a (plan 008, DA-7).
 */
final class OutgoingMailable extends Mailable
{
    public function __construct(OutgoingMail $mail)
    {
        $this->to($mail->to->email, $mail->to->name)
            ->subject($mail->subject)
            ->html($mail->html);

        if ($mail->fromName !== null) {
            $this->from((string) config('mail.from.address'), $mail->fromName);
        }

        if ($mail->replyTo !== null) {
            $this->replyTo($mail->replyTo);
        }

        $this->withSymfonyMessage(function (Email $message) use ($mail): void {
            $message->text($mail->text);
        });
    }
}
