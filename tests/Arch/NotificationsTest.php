<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;

/*
 * ADR-0009 (decisión 5): nadie envía correo con `Mail::` fuera del módulo de
 * notificaciones; el destinatario solo lo fija `SendNotification`.
 */

arch('only the notifications module talks to the mail facade')
    ->expect(Mail::class)
    ->toOnlyBeUsedIn(['App\Support\Notifications', 'App\Mail']);

arch('the transactional mailer adapters live in the notifications module')
    ->expect('App\Support\Notifications\Mailers')
    ->toImplement('App\Support\Notifications\TransactionalMailer');

arch('queued mail jobs are only created by the notification action')
    ->expect('App\Jobs\SendTransactionalMail')
    ->toOnlyBeUsedIn('App\Actions\Notifications');
