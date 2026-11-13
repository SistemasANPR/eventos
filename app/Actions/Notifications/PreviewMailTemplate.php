<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Http\Requests\Notifications\PreviewMailTemplateRequest;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Notifications\RenderedMail;
use App\Support\Notifications\SampleMailData;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Renderiza un borrador de plantilla con datos de ejemplo (plan 008, H-008-4, CA-008-16).
 * No guarda nada, no encola nada y no envía ningún correo. Aplica las mismas reglas que al
 * guardar: un borrador que no se podría guardar no se previsualiza.
 *
 * @throws AuthorizationException si quien pide la vista previa no tiene `mail-templates.manage`
 * @throws ValidationException si el borrador usa variables fuera de la lista blanca o campos inválidos
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class PreviewMailTemplate
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly RenderMailTemplate $renderer,
    ) {}

    public function handle(User $actor, Organization $organization, string $key, string $locale, string $subject, string $body): RenderedMail
    {
        return CurrentOrganization::run($organization, function () use ($actor, $key, $locale, $subject, $body): RenderedMail {
            $this->authorizeActor($actor, 'update', MailTemplate::class);

            $descriptor = $this->catalog->get($key);

            $validated = Validator::make(
                ['locale' => $locale, 'subject' => $subject, 'body' => $body],
                PreviewMailTemplateRequest::fieldRules($descriptor->locales),
            )->validate();

            return $this->renderer->handle($key, $locale, SampleMailData::for($descriptor), $validated['subject'], $validated['body']);
        });
    }
}
