<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Enums\MailDispatchStatus;
use App\Jobs\SendTransactionalMail;
use App\Models\MailDispatch;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Support\Notifications\MailRecipient;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\NotificationDescriptor;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Único punto por el que el sistema envía correo del catálogo (ADR-0009, decisión 5).
 *
 * Fija el destinatario con datos del sistema: ni la plantilla ni los valores de las
 * variables pueden cambiarlo ni añadir copias. Idempotente por `clave + hecho de negocio`
 * (+ `nonce` cuando una misma solicitud puede repetirse, como el enlace de acceso, DA-9).
 *
 * Una notificación NO crítica que la organización desactivó (plan 008, H-008-5) no se
 * envía: queda registrada como `disabled`. Una crítica se envía siempre. Respeta el límite
 * por destinatario y hora del catálogo (DA-8): lo que lo supera queda `throttled` y no se
 * encola. El envío real ocurre en `SendTransactionalMail`, por cola, con la plantilla que
 * resuelve `ResolveMailTemplate` (evento, organización o sistema).
 *
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class SendNotification
{
    public function __construct(private readonly NotificationCatalog $catalog) {}

    /**
     * @param  array<string, string>  $variables
     * @param  string  $factId  identificador del hecho de negocio que la dispara (p. ej. el id de la propuesta)
     * @param  string|null  $eventId  evento al que pertenece el hecho, para usar su plantilla si la tiene
     */
    public function handle(
        string $key,
        MailRecipient $recipient,
        array $variables,
        string $factId,
        Organization $organization,
        ?string $nonce = null,
        ?string $eventId = null,
    ): MailDispatch {
        $descriptor = $this->catalog->get($key);
        $idempotencyKey = $key.':'.$factId.($nonce !== null ? ':'.$nonce : '');

        return CurrentOrganization::run($organization, function () use ($descriptor, $recipient, $variables, $idempotencyKey, $eventId): MailDispatch {
            $existing = MailDispatch::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }

            $hash = hash_hmac('sha256', mb_strtolower(trim($recipient->email)), (string) config('app.key'));
            $status = $this->initialStatus($descriptor, $hash);

            try {
                $dispatch = new MailDispatch;
                $dispatch->forceFill([
                    'notification_key' => $descriptor->key,
                    'idempotency_key' => $idempotencyKey,
                    'recipient_hash' => $hash,
                    'status' => $status,
                ])->save();
            } catch (UniqueConstraintViolationException) {
                // Otra petición registró el mismo hecho a la vez: gana la primera.
                return MailDispatch::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            }

            if ($status === MailDispatchStatus::Queued) {
                SendTransactionalMail::dispatch(
                    $dispatch->id,
                    $dispatch->organization_id,
                    $descriptor->key,
                    $recipient,
                    $variables,
                    $eventId,
                )->afterCommit();
            }

            return $dispatch;
        });
    }

    private function initialStatus(NotificationDescriptor $descriptor, string $recipientHash): MailDispatchStatus
    {
        if (! $descriptor->critical && $this->isDisabled($descriptor->key)) {
            return MailDispatchStatus::Disabled;
        }

        if ($descriptor->recipientLimit !== null && $this->recentCount($descriptor->key, $recipientHash) >= $descriptor->recipientLimit) {
            return MailDispatchStatus::Throttled;
        }

        return MailDispatchStatus::Queued;
    }

    /** Solo una fila explícita con `is_enabled = false` desactiva; sin fila, la notificación está activa. */
    private function isDisabled(string $key): bool
    {
        return NotificationSetting::query()
            ->where('notification_key', $key)
            ->where('is_enabled', false)
            ->exists();
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
