<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Actions\Notifications\SendNotification;
use App\Enums\CallState;
use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\MailDispatch;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionParticipant;
use App\Support\Calls\CallClosingDate;
use App\Support\Calls\ResolveCallState;
use App\Support\Notifications\MailRecipient;
use App\Support\Submissions\ClosingRemindersReport;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recordatorio de cierre de la convocatoria a los autores con propuestas enviadas (plan 012, F3, H-012-4,
 * CA-012-23 a 26). La corre la tarea diaria `calls:send-closing-reminders` organización por organización
 * (con su contexto) y envía `call.closing_reminder` por `SendNotification`, nunca con `Mail::`.
 *
 * - Cuándo: faltan `submissions.closing_reminder_days` días (3 por defecto) o menos para el cierre, contados
 *   como días de calendario en la zona horaria del evento sobre el instante UTC guardado (CA-012-25). Se usa
 *   «o menos» y no «exactamente» para que una corrida que no se ejecutó un día no pierda el recordatorio.
 * - Quién: una persona por convocatoria, la que envió (participante principal) cada propuesta en estado
 *   `submitted`, con su correo sin distinguir mayúsculas. Una propuesta retirada, decidida o de una
 *   convocatoria cerrada, sin publicar o ya vencida no cuenta (CA-012-24).
 * - Una sola vez: la clave de idempotencia es la convocatoria más un hash del correo (CA-012-23): ejecutar
 *   el comando dos veces no duplica nada, ni siquiera si el autor tiene varias propuestas.
 * - `edit_hint`: invita a corregir la propuesta solo si la edición por el autor (plan 006, H-006-4) está
 *   disponible para alguna de sus propuestas; si no, queda vacío y el correo es informativo (CA-012-26).
 *   Ningún correo lleva enlace de acceso: enlaza a «pedir mi enlace».
 * - El fallo de un autor se registra y no detiene a los demás; queda en el informe.
 */
final class SendClosingReminders
{
    public function __construct(
        private readonly SendNotification $notify,
        private readonly PrepareAuthorEdit $authorEdit,
    ) {}

    public function handle(Organization $organization, ?CarbonImmutable $now = null): ClosingRemindersReport
    {
        $now ??= CarbonImmutable::now();
        $days = max(1, (int) config('submissions.closing_reminder_days'));

        return CurrentOrganization::run($organization, function () use ($organization, $now, $days): ClosingRemindersReport {
            $sent = 0;
            $failed = 0;

            // Preselección por UTC con un día de margen; el cálculo exacto usa la zona de cada evento.
            CallForSubmissions::query()
                ->where('closes_at', '>', $now)
                ->where('closes_at', '<=', $now->addDays($days + 1))
                ->orderBy('id')
                ->each(function (CallForSubmissions $call) use ($organization, $now, $days, &$sent, &$failed): void {
                    $event = Event::query()->findOrFail($call->event_id);

                    if (ResolveCallState::for($call, $now) !== CallState::Open || $this->daysLeft($call, $event, $now) > $days) {
                        return;
                    }

                    foreach ($this->authors($call) as $submissions) {
                        try {
                            $sent += $this->remind($organization, $call, $event, $submissions) ? 1 : 0;
                        } catch (Throwable $exception) {
                            $failed++;
                            report($exception);
                            Log::error('No se pudo enviar el recordatorio de cierre a un autor.', [
                                'call_id' => $call->id,
                                'exception' => $exception::class,
                            ]);
                        }
                    }
                });

            return new ClosingRemindersReport($sent, $failed);
        });
    }

    /** Días de calendario entre hoy y el día del cierre, ambos en la zona del evento. */
    private function daysLeft(CallForSubmissions $call, Event $event, CarbonImmutable $now): int
    {
        $closing = CarbonImmutable::instance($call->closes_at)->setTimezone($event->timezone)->startOfDay();
        $today = $now->setTimezone($event->timezone)->startOfDay();

        return (int) $today->diffInDays($closing);
    }

    /**
     * Las propuestas enviadas de la convocatoria, agrupadas por el correo (en minúsculas) de quien las envió.
     *
     * @return array<string, list<Submission>>
     */
    private function authors(CallForSubmissions $call): array
    {
        $byEmail = [];

        Submission::query()
            ->where('call_for_submissions_id', $call->id)
            ->where('origin', SubmissionOrigin::Call)
            ->where('status', SubmissionStatus::Submitted)
            ->with(['participants' => static fn ($query) => $query->where('is_primary', true)])
            ->orderBy('submitted_at')
            ->get()
            ->each(function (Submission $submission) use (&$byEmail): void {
                $primary = $submission->participants->first();

                if ($primary instanceof SubmissionParticipant) {
                    $byEmail[mb_strtolower(trim($primary->email))][] = $submission;
                }
            });

        return $byEmail;
    }

    /**
     * @param  list<Submission>  $submissions  las propuestas enviadas de un mismo autor en la convocatoria
     * @return bool verdadero si este recordatorio se creó ahora (falso si ya se había enviado)
     */
    private function remind(Organization $organization, CallForSubmissions $call, Event $event, array $submissions): bool
    {
        $first = $submissions[0];
        $primary = SubmissionParticipant::query()->where('submission_id', $first->id)->where('is_primary', true)->firstOrFail();
        $locale = $first->locale;
        $nonce = substr(hash_hmac('sha256', mb_strtolower(trim($primary->email)), (string) config('app.key')), 0, 32);

        // Ya se le avisó en una corrida anterior: no se recalcula nada (la tarea corre todos los días).
        if (MailDispatch::query()->where('idempotency_key', 'call.closing_reminder:'.$call->id.':'.$nonce)->exists()) {
            return false;
        }

        $editable = array_filter($submissions, fn (Submission $submission): bool => $this->authorEdit->handle($submission, $event, $locale) !== null) !== [];

        $dispatch = $this->notify->handle(
            key: 'call.closing_reminder',
            recipient: new MailRecipient($primary->email, $primary->full_name, $locale),
            variables: [
                'author_name' => $primary->full_name,
                'event_name' => $event->getTranslation('name', $locale),
                'closing_date' => CallClosingDate::for($call, $event, $locale),
                'edit_hint' => $editable ? __('submission_results.edit_hint', [], $locale) : '',
                'request_link_url' => route('author.request-link.show', ['organization' => $organization->slug, 'event' => $event->slug]),
            ],
            factId: $call->id,
            organization: $organization,
            nonce: $nonce,
            eventId: $event->id,
        );

        return $dispatch->wasRecentlyCreated;
    }
}
