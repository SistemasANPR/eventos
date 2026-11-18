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
 * Elimina (borrado lógico) a un ponente (plan 021, DA-13, CA-021-15, CA-021-16). Solo `org_admin`
 * (`speakers.delete`). La ficha deja de aparecer en las listas y libera su correo; sus actividades
 * no se borran (el borrado de uno no arrastra al otro) y su fotografía se conserva en disco hasta la
 * eliminación definitiva a pedido (`docs/privacidad.md`). Se ejecuta en la organización DEL PONENTE
 * y deja auditoría con identificadores, sin datos personales.
 *
 * @throws AuthorizationException si quien elimina no tiene `speakers.delete` en la organización del ponente
 */
final class DeleteSpeaker
{
    use AuthorizesInOrganization;

    public function handle(User $actor, Speaker $speaker): void
    {
        $organization = Organization::query()->findOrFail($speaker->organization_id);

        CurrentOrganization::run($organization, function () use ($actor, $speaker): void {
            $this->authorizeActor($actor, 'delete', $speaker);

            DB::transaction(function () use ($actor, $speaker): void {
                $speaker->delete();

                activity()
                    ->causedBy($actor)
                    ->performedOn($speaker)
                    ->event('speaker_deleted')
                    ->withProperties([
                        'organization_id' => $speaker->organization_id,
                        'event_id' => $speaker->event_id,
                        'speaker_id' => $speaker->id,
                    ])
                    ->log('speaker_deleted');
            });
        });
    }
}
