<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Models\Organization;
use App\Models\Speaker;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Publica o despublica un ponente (plan 021, DA-6, DA-13): el cambio rápido de la lista. Publicar
 * limpia la marca «oculto por reversa» (quien republica a mano decide que se vea). Despublicar a mano
 * no la pone: esa marca es solo de la reversa de una propuesta. Es idempotente: pedir el estado que ya
 * tiene no cambia nada ni deja auditoría. Se ejecuta en la organización DEL PONENTE.
 *
 * @throws AuthorizationException si quien cambia no tiene `speakers.manage` en la organización del ponente
 */
final class SetSpeakerPublication
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Speaker $speaker, bool $published): Speaker
    {
        $organization = Organization::query()->findOrFail($speaker->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $speaker, $published): Speaker {
            $this->authorizeActor($actor, 'update', $speaker);

            return DB::transaction(function () use ($actor, $speaker, $published): Speaker {
                $speaker = Speaker::query()->whereKey($speaker->id)->lockForUpdate()->firstOrFail();

                if ($speaker->is_published === $published) {
                    return $speaker;
                }

                $speaker->forceFill([
                    'is_published' => $published,
                    'hidden_by_reversal_at' => $published ? null : $speaker->hidden_by_reversal_at,
                ])->save();

                $event = $published ? 'speaker_published' : 'speaker_unpublished';

                activity()
                    ->causedBy($actor)
                    ->performedOn($speaker)
                    ->event($event)
                    ->withProperties([
                        'organization_id' => $speaker->organization_id,
                        'event_id' => $speaker->event_id,
                        'speaker_id' => $speaker->id,
                    ])
                    ->log($event);

                return $speaker;
            });
        });
    }
}
