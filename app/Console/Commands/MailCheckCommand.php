<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnóstico del correo transaccional: muestra la configuración (sin revelar la clave) y envía un
 * correo de prueba DIRECTO, sin cola, para ver el error exacto del proveedor (ADR-0009). Útil tras
 * configurar `RESEND_API_KEY` y `MAIL_FROM_ADDRESS` en un entorno nuevo. Solo por consola.
 */
final class MailCheckCommand extends Command
{
    protected $signature = 'app:mail-check {email : Dirección a la que se envía la prueba}';

    protected $description = 'Muestra la configuración del correo y envía un correo de prueba sin cola';

    public function handle(TransactionalMailer $mailer): int
    {
        $email = (string) $this->argument('email');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('La dirección no es válida.');

            return self::FAILURE;
        }

        $this->line('Proveedor (NOTIFICATIONS_MAILER): '.(string) config('notifications.driver'));
        $this->line('Remitente (MAIL_FROM_ADDRESS): '.(string) config('mail.from.address'));
        $this->line('Clave de Resend: '.$this->describeKey((string) config('services.resend.key')));
        $this->line('Cola (QUEUE_CONNECTION): '.(string) config('queue.default'));
        $this->line('Procesador de la cola en este contenedor (RUN_QUEUE_WORKER): '.(getenv('RUN_QUEUE_WORKER') ?: 'no'));

        try {
            $mailer->send(new OutgoingMail(
                to: new MailRecipient($email, 'Prueba', 'es'),
                subject: 'Prueba de correo',
                html: '<p>Si lees esto, el envío de correo funciona.</p>',
                text: 'Si lees esto, el envío de correo funciona.',
            ));
        } catch (Throwable $exception) {
            $this->error('El proveedor rechazó el envío: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Envío aceptado por el proveedor para {$email}. Revisa la bandeja y el correo no deseado.");

        return self::SUCCESS;
    }

    /** Nunca imprime la clave: solo si existe, su prefijo `re_` y su longitud. */
    private function describeKey(string $key): string
    {
        if ($key === '') {
            return 'NO está definida';
        }

        return (str_starts_with($key, 're_') ? 'empieza por re_' : 'NO empieza por re_').', '.strlen($key).' caracteres';
    }
}
