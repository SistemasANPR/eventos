<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\MailDispatchStatus;
use App\Jobs\SendTransactionalMail;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Único punto por el que el sistema envía correo del catálogo (ADR-0009, decisión 5).
 *
 * Fija el destinatario con datos del sistema: ni la plantilla ni los valores de las
 * variables pueden cambiarlo ni añadir copias. Idempotente por `clave + hecho de negocio`
 * (+ `nonce` cuando una misma solicitud puede repetirse, como el enlace de acceso, DA-9).
 * Respeta el límite por destinatario y hora del catálogo (DA-8): lo que lo supera queda
 * `throttled` y no se encola. El envío real ocurre en `SendTransactionalMail`, por cola.
 *
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class SendNotification
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * @param  array<string, string>  $variables
     * @param  string  $factId  identificador del hecho de negocio que la dispara (p. ej. el id de la propuesta)
     */
    public function handle(
        string $key,
        MailRecipient $recipient,
        array $variables,
        string $factId,
        Organization $organization,
        ?string $nonce = null,
    ): MailDispatch {
        $descriptor = $this->catalog->get($key);
        $idempotencyKey = $key.':'.$factId.($nonce !== null ? ':'.$nonce : '');

        return CurrentOrganization::run($organization, function () use ($descriptor, $recipient, $variables, $idempotencyKey): MailDispatch {
            $existing = MailDispatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $hash = hash_hmac('sha256', mb_strtolower(trim($recipient->email)), (string) config('app.key'));
            $throttled = $descriptor->recipientLimit !== null
                && $this->recentCount($descriptor->key, $hash) >= $descriptor->recipientLimit;

            try {
                $dispatch = new MailDispatch;
                $dispatch->forceFill([
                    'notification_key' => $descriptor->key,
                    'idempotency_key' => $idempotencyKey,
                    'recipient_hash' => $hash,
                    'status' => $throttled ? MailDispatchStatus::Throttled : MailDispatchStatus::Queued,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // Otra petición registró el mismo hecho a la vez: gana la primera.
                return MailDispatch::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            if (! $throttled) {
                SendTransactionalMail::dispatch(
                    $dispatch->id,
                    $dispatch->organization_id,
                    $descriptor->key,
                    $recipient,
                    $variables,
                )->afterCommit();
            }

            return $dispatch;
        });
    }

    private function recentCount(string $key, string $recipientHash): int
    {
        return MailDispatch::query()
            ->where('notification_key', $key)
            ->where('recipient_hash', $recipientHash)
            ->whereIn('status', [MailDispatchStatus::Queued, MailDispatchStatus::Sent])
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }
}
