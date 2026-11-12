<?php

declare(strict_types=1);

namespace App\Support\Notifications\Mailers;

use App\Mail\OutgoingMailable;
use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;
use Illuminate\Support\Facades\Mail;

/** Base de los adaptadores que entregan el correo por un mailer con nombre de `config/mail.php`. */
abstract class LaravelMailer implements TransactionalMailer
{
    /** Nombre del mailer en `config/mail.php`. */
    abstract protected function mailerName(): string;

    public function send(OutgoingMail $mail): void
    {
        Mail::mailer($this->mailerName())->send((new OutgoingMailable($mail))->mailer($this->mailerName()));
    }
}
