<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Notifications\RenderMailTemplate;
use App\Actions\Notifications\ResolveMailTemplate;
use App\Enums\MailDispatchStatus;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\OutgoingMail;
use App\Support\Notifications\TransactionalMailer;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía un correo del catálogo por el proveedor configurado (plan 008, H-008-6).
 *
 * Idempotente: si el registro ya figura como enviado no vuelve a enviar (un reintento de
 * la cola no duplica el correo). 5 intentos con espera creciente; agotados, `failed()`
 * deja el registro como `failed`, visible, y nunca se descarta en silencio. La carga va
 * cifrada en la cola porque puede llevar enlaces de acceso de un solo uso.
 */
final class SendTransactionalMail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly string $dispatchId,
        public readonly string $organizationId,
        public readonly string $notificationKey,
        public readonly MailRecipient $recipient,
        public readonly array $variables,
        public readonly ?string $eventId = null,
    ) {}

    /** @return list<int> segundos de espera entre intentos, siempre crecientes */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(RenderMailTemplate $renderer, ResolveMailTemplate $resolver, TransactionalMailer $mailer): void
    {
        $this->inOrganization(function () use ($renderer, $resolver, $mailer): void {
            $dispatch = MailDispatch::query()->findOrFail($this->dispatchId);

            if ($dispatch->status === MailDispatchStatus::Sent) {
                return;
            }

            $dispatch->forceFill(['attempts' => $dispatch->attempts + 1])->save();

            $template = $resolver->handle($this->notificationKey, $this->recipient->locale, CurrentOrganization::get(), $this->eventId);
            $rendered = $renderer->handle($this->notificationKey, $this->recipient->locale, $this->variables, $template->subject, $template->body);

            $mailer->send(new OutgoingMail(
                $this->recipient,
                $rendered->subject,
                $rendered->html,
                $rendered->text,
                $template->fromName,
                $template->replyTo,
            ));

            $dispatch->forceFill(['status' => MailDispatchStatus::Sent, 'sent_at' => now()])->save();
        });
    }

    /** Agotados los intentos: queda visible como fallido. El registro no lleva datos personales. */
    public function failed(Throwable $exception): void
    {
        $this->inOrganization(function () use ($exception): void {
            $dispatch = MailDispatch::query()->find($this->dispatchId);

            if ($dispatch === null || $dispatch->status === MailDispatchStatus::Sent) {
                return;
            }

            $dispatch->forceFill(['status' => MailDispatchStatus::Failed])->save();

            Log::error('Correo transaccional fallido tras agotar los intentos.', [
                'dispatch_id' => $this->dispatchId,
                'notification_key' => $this->notificationKey,
                'exception' => $exception::class,
            ]);
        });
    }

    /** @param  \Closure(): void  $callback */
    private function inOrganization(\Closure $callback): void
    {
        CurrentOrganization::run(Organization::query()->findOrFail($this->organizationId), function () use ($callback): bool {
            $callback();

            return true;
        });
    }
}
