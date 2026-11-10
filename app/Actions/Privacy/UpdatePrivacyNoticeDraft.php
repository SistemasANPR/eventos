<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

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
 * Edita el texto de un borrador (plan 003, CA-003-11 y -14). Una versión
 * publicada es inmutable (también en la base): para cambiarla se crea la
 * versión siguiente. El texto se sanitiza antes de guardarse y reemplaza al
 * anterior por completo.
 *
 * @throws AuthorizationException si quien edita no tiene `privacy_notices.manage` en la organización del aviso
 * @throws ValidationException si la versión ya está publicada o el texto no es válido
 */
final class UpdatePrivacyNoticeDraft
{
    use AuthorizesInOrganization;

    public function __construct(private readonly NoticeTexts $texts) {}

    /** @param  array<string, mixed>  $body */
    public function handle(User $actor, PrivacyNotice $notice, array $body): PrivacyNotice
    {
        $organization = Organization::query()->findOrFail($notice->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $notice, $body): PrivacyNotice {
            $this->authorizeActor($actor, 'update', $notice);

            if ($notice->isPublished()) {
                throw ValidationException::withMessages(['body' => __('privacy.published_is_immutable')]);
            }

            $clean = $this->texts->prepare($body);

            return DB::transaction(function () use ($actor, $notice, $clean): PrivacyNotice {
                $notice->setTranslations('body', $clean)->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($notice)
                    ->event('privacy_notice_updated')
                    ->withProperties(['organization_id' => $notice->organization_id, 'version' => $notice->version])
                    ->log('privacy_notice_updated');

                return $notice;
            });
        });
    }
}
