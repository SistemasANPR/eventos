<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Enums\CallStatus;
use App\Exceptions\Domain\CallStatusTransitionInvalid;
use App\Http\Requests\Calls\ChangeCallStatusRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\EventAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Pausar, cerrar y reabrir una convocatoria a mano (plan 004, H-004-4, DA-1).
 * Transiciones: publicada → en pausa (pausar), publicada o en pausa → cerrada
 * (cerrar), en pausa → publicada (reanudar) y cerrada → publicada (reabrir).
 * Un borrador no cambia de estado aquí: se publica con `PublishCall`.
 *
 * Al volver a `published` el estado público se calcula con las fechas; si el cierre
 * ya pasó (CA-004-26) hace falta una nueva `closes_at` (hora local del evento),
 * que debe ser futura y posterior a la apertura. Queda auditado con el estado
 * anterior y el nuevo (y el cierre anterior y nuevo si cambió).
 *
 * @throws AuthorizationException si quien cambia el estado no tiene `calls.manage` en la organización de la convocatoria
 * @throws CallStatusTransitionInvalid si la transición no está permitida (código `CALL_STATUS_TRANSITION_INVALID`)
 * @throws ValidationException si la nueva fecha de cierre no es válida o falta
 */
final class ChangeCallStatus
{
    use AuthorizesInOrganization;

    /** @var array<string, list<CallStatus>> estados de destino permitidos por estado de origen */
    private const TRANSITIONS = [
        'published' => [CallStatus::Paused, CallStatus::Closed],
        'paused' => [CallStatus::Published, CallStatus::Closed],
        'closed' => [CallStatus::Published],
    ];

    /** @param  array<string, mixed>|null  $data  `closes_at` (hora local del evento, `Y-m-d H:i`), solo al volver a publicada */
    public function handle(User $actor, CallForSubmissions $call, CallStatus $target, ?array $data = null): CallForSubmissions
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call, $target, $data): CallForSubmissions {
            $this->authorizeActor($actor, 'changeStatus', $call);

            $current = $call->status;

            if (! in_array($target, self::TRANSITIONS[$current->value] ?? [], true)) {
                throw CallStatusTransitionInvalid::from($current, $target);
            }

            $closes = $target === CallStatus::Published ? $this->newClose($call, $data ?? []) : null;

            return DB::transaction(function () use ($actor, $call, $current, $target, $closes): CallForSubmissions {
                $old = ['status' => $current->value];
                $new = ['status' => $target->value];

                if ($closes !== null) {
                    $old['closes_at'] = $call->closes_at->utc()->format('Y-m-d\TH:i:s\Z');
                    $new['closes_at'] = $closes->utc()->format('Y-m-d\TH:i:s\Z');
                    $call->closes_at = $closes;
                }

                $call->forceFill(['status' => $target])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($call)
                    ->event('call_status_changed')
                    ->withProperties(['organization_id' => $call->organization_id, 'old' => $old, 'new' => $new])
                    ->log('call_status_changed');

                return $call;
            });
        });
    }

    /**
     * Nueva fecha de cierre al reabrir, en UTC, o `null` si se conserva la actual.
     * Sin fecha nueva, el cierre actual debe seguir en el futuro (CA-004-26).
     *
     * @param  array<string, mixed>  $data
     */
    private function newClose(CallForSubmissions $call, array $data): ?Carbon
    {
        $event = Event::query()->findOrFail($call->event_id);
        $validated = Validator::make($data, ChangeCallStatusRequest::rulesFor($event, $call))->validate();
        $local = $validated['closes_at'] ?? null;

        if (is_string($local) && $local !== '') {
            return Carbon::instance(EventAttributes::toUtc($local, $event->timezone));
        }

        if (! $call->closes_at->gt(Date::now())) {
            throw ValidationException::withMessages(['closes_at' => __('calls.reopen_needs_new_close')]);
        }

        return null;
    }
}
