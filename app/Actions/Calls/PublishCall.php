<?php

declare(strict_types=1);

namespace App\Actions\Calls;

use App\Enums\CallStatus;
use App\Exceptions\Domain\PrivacyNoticeMissing;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publica una convocatoria en borrador (plan 004, CA-004-9 y -10, DA-6): exige
 * que el evento tenga su aviso de privacidad PUBLICADO en cada idioma de la
 * convocatoria; sin un texto que consentir no se abre. Un borrador de aviso no
 * cuenta. Queda auditado con el estado anterior y el nuevo.
 *
 * @throws AuthorizationException si quien publica no tiene `calls.manage` en la organización de la convocatoria
 * @throws PrivacyNoticeMissing si falta el aviso publicado en algún idioma (código `PRIVACY_NOTICE_MISSING`)
 * @throws ValidationException si no es un borrador
 */
final class PublishCall
{
    use AuthorizesInOrganization;

    public function handle(User $actor, CallForSubmissions $call): CallForSubmissions
    {
        $organization = Organization::query()->findOrFail($call->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $call): CallForSubmissions {
            $this->authorizeActor($actor, 'publish', $call);

            if ($call->status !== CallStatus::Draft) {
                throw ValidationException::withMessages(['status' => __('calls.only_draft_can_be_published')]);
            }

            $event = Event::query()->findOrFail($call->event_id);
            $missing = $this->localesWithoutPublishedNotice($event, $call);

            if ($missing !== []) {
                throw PrivacyNoticeMissing::forLocales($missing);
            }

            return DB::transaction(function () use ($actor, $call): CallForSubmissions {
                $call->forceFill(['status' => CallStatus::Published])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($call)
                    ->event('call_published')
                    ->withProperties([
                        'organization_id' => $call->organization_id,
                        'old' => ['status' => CallStatus::Draft->value],
                        'new' => ['status' => CallStatus::Published->value],
                    ])
                    ->log('call_published');

                return $call;
            });
        });
    }

    /**
     * Idiomas de la convocatoria en los que el aviso VIGENTE (la última versión publicada) no tiene texto.
     *
     * @return list<string>
     */
    private function localesWithoutPublishedNotice(Event $event, CallForSubmissions $call): array
    {
        $notice = PrivacyNotice::currentPublished($event);

        if ($notice === null) {
            return $call->locales;
        }

        return array_values(array_filter(
            $call->locales,
            static fn (string $locale): bool => trim((string) $notice->getTranslation('body', $locale, false)) === '',
        ));
    }
}
