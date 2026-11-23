<?php

declare(strict_types=1);

use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;

/*
 * `app:mail-check`: diagnóstico del correo transaccional. Un proveedor simulado evita enviar nada real
 * y la clave nunca se imprime.
 */

it('manda un correo de prueba directo y no revela la clave', function (): void {
    $sent = [];
    app()->instance(TransactionalMailer::class, new class($sent) implements TransactionalMailer
    {
        /** @param  list<OutgoingMail>  $sent */
        public function __construct(public array &$sent) {}

        public function send(OutgoingMail $mail): void
        {
            $this->sent[] = $mail;
        }
    });
    config(['services.resend.key' => 're_clave_secreta_de_prueba_1234567890']);

    $this->artisan('app:mail-check', ['email' => 'ana@example.test'])
        ->expectsOutputToContain('empieza por re_')
        ->doesntExpectOutputToContain('clave_secreta_de_prueba')
        ->expectsOutputToContain('Envío aceptado')
        ->assertSuccessful();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->to->email)->toBe('ana@example.test');
});

it('muestra el error exacto del proveedor cuando rechaza el envio', function (): void {
    app()->instance(TransactionalMailer::class, new class implements TransactionalMailer
    {
        public function send(OutgoingMail $mail): void
        {
            throw new RuntimeException('You can only send testing emails to your own email address.');
        }
    });

    $this->artisan('app:mail-check', ['email' => 'ana@example.test'])
        ->expectsOutputToContain('You can only send testing emails to your own email address.')
        ->assertFailed();
});

it('rechaza una direccion que no es valida sin enviar nada', function (): void {
    $this->artisan('app:mail-check', ['email' => 'no-es-un-correo'])
        ->expectsOutputToContain('no es válida')
        ->assertFailed();
});
