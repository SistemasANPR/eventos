<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Privacy\NoticeTexts;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea el borrador de la siguiente versión del aviso de un evento (plan 003,
 * CA-003-11 y -14). Sin texto, parte de la versión publicada más reciente
 * («crear una versión nueva a partir de ella»); el evento tiene a lo sumo UN
 * borrador abierto. El texto se sanitiza antes de guardarse.
 *
 * @throws AuthorizationException si quien lo crea no tiene `privacy_notices.manage` en la organización del evento
 * @throws ValidationException si no hay texto, el idioma no es válido o ya hay un borrador abierto
 */
final class CreatePrivacyNoticeDraft
{
    use AuthorizesInOrganization;

    public function __construct(private readonly NoticeTexts $texts) {}

    /** @param  array<string, mixed>|null  $body  texto por idioma; `null` copia la versión publicada vigente */
    public function handle(User $actor, Event $event, ?array $body = null): PrivacyNotice
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $body): PrivacyNotice {
            $this->authorizeActor($actor, 'create', PrivacyNotice::class, $event);

            return DB::transaction(function () use ($actor, $event, $body): PrivacyNotice {
                // Serializa las altas de versión del mismo evento: sin el bloqueo, dos borradores
                // simultáneos calcularían el mismo número (la base lo impide, pero con un 500).
                Event::query()->whereKey($event->id)->lockForUpdate()->sole();

                if (PrivacyNotice::query()->where('event_id', $event->id)->whereNull('published_at')->exists()) {
                    throw ValidationException::withMessages(['body' => __('privacy.draft_already_open')]);
                }

                $source = $body ?? PrivacyNotice::currentPublished($event)?->getTranslations('body');

                if ($source === null) {
                    throw ValidationException::withMessages(['body' => __('privacy.body_required')]);
                }

                $notice = new PrivacyNotice;
                $notice->event_id = $event->id;
                $notice->version = (int) PrivacyNotice::query()->where('event_id', $event->id)->max('version') + 1;
                $notice->setTranslations('body', $this->texts->prepare($source));
                $notice->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($notice)
                    ->event('privacy_notice_created')
                    ->withProperties(['organization_id' => $notice->organization_id, 'version' => $notice->version])
                    ->log('privacy_notice_created');

                return $notice;
            });
        });
    }
}
