<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

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
 * Publica un borrador (plan 003, CA-003-12, -13 y -18): exige texto en el idioma
 * por defecto del evento. Queda inmutable con fecha y autor, y se audita. La
 * versión anterior deja de ser la vigente a partir de este momento.
 *
 * @throws AuthorizationException si quien publica no tiene `privacy_notices.manage` en la organización del aviso
 * @throws ValidationException si ya está publicada o falta el texto del idioma por defecto (indica el campo)
 */
final class PublishPrivacyNotice
{
    use AuthorizesInOrganization;

    public function handle(User $actor, PrivacyNotice $notice): PrivacyNotice
    {
        $organization = Organization::query()->findOrFail($notice->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $notice): PrivacyNotice {
            $this->authorizeActor($actor, 'publish', $notice);

            if ($notice->isPublished()) {
                throw ValidationException::withMessages(['body' => __('privacy.already_published')]);
            }

            $event = Event::query()->findOrFail($notice->event_id);
            $defaultText = trim((string) $notice->getTranslation('body', $event->default_locale, false));

            if ($defaultText === '') {
                throw ValidationException::withMessages([
                    "body.{$event->default_locale}" => __('privacy.default_language_required', ['locale' => $event->default_locale]),
                ]);
            }

            return DB::transaction(function () use ($actor, $notice): PrivacyNotice {
                $notice->forceFill(['published_at' => now(), 'published_by' => $actor->id])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($notice)
                    ->event('privacy_notice_published')
                    ->withProperties([
                        'organization_id' => $notice->organization_id,
                        'event_id' => $notice->event_id,
                        'version' => $notice->version,
                    ])
                    ->log('privacy_notice_published');

                return $notice;
            });
        });
    }
}
