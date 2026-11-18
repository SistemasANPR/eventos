<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\Http\Requests\Notifications\UpdateMailTemplateRequest;
use App\Models\MailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Notifications\NotificationCatalog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Guarda la plantilla de la organización para una notificación y un idioma (plan 008,
 * H-008-4, CA-008-5, CA-008-15 y CA-008-18). Crea la fila o actualiza la existente (nivel
 * organización, `event_id` nulo). Se ejecuta en la organización indicada y vuelve a autorizar.
 *
 * Solo se admiten variables de la lista blanca de la notificación y ningún campo de cabecera
 * con saltos de línea. Se audita quién la cambió y qué campos, nunca el cuerpo completo.
 *
 * @throws AuthorizationException si quien guarda no tiene `mail-templates.manage` en la organización
 * @throws ValidationException si algún campo o variable no cumple las reglas
 * @throws InvalidArgumentException si la clave no está en el catálogo
 */
final class UpdateMailTemplate
{
    use AuthorizesInOrganization;

    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly RenderMailTemplate $renderer,
    ) {}

    /** @param  array<string, mixed>  $data  `subject`, `body`, `from_name` y `reply_to` */
    public function handle(User $actor, Organization $organization, string $key, string $locale, array $data): MailTemplate
    {
        return CurrentOrganization::run($organization, function () use ($actor, $organization, $key, $locale, $data): MailTemplate {
            $this->authorizeActor($actor, 'update', MailTemplate::class);

            $descriptor = $this->catalog->get($key);

            $validated = Validator::make(
                [...$data, 'locale' => $locale],
                UpdateMailTemplateRequest::fieldRules($descriptor->locales),
            )->validate();

            $this->renderer->validate($key, $validated['subject'], $validated['body']);

            return DB::transaction(function () use ($actor, $organization, $key, $locale, $validated): MailTemplate {
                $template = MailTemplate::query()
                    ->where('notification_key', $key)
                    ->where('locale', $locale)
                    ->whereNull('event_id')
                    ->lockForUpdate()
                    ->first();

                $isNew = $template === null;
                $template ??= (new MailTemplate)->forceFill([
                    'notification_key' => $key,
                    'locale' => $locale,
                    'event_id' => null,
                    'created_by' => $actor->id,
                ]);

                $new = [
                    'subject' => $validated['subject'],
                    'body' => $validated['body'],
                    'from_name' => ($validated['from_name'] ?? '') === '' ? null : $validated['from_name'],
                    'reply_to' => ($validated['reply_to'] ?? '') === '' ? null : $validated['reply_to'],
                ];

                $changed = array_keys(array_filter(
                    $new,
                    fn (mixed $value, string $field): bool => $template->getAttribute($field) !== $value,
                    ARRAY_FILTER_USE_BOTH,
                ));

                if (! $isNew && $changed === []) {
                    return $template;
                }

                $template->forceFill([...$new, 'updated_by' => $actor->id])->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($template)
                    ->event('mail_template_updated')
                    ->withProperties([
                        'organization_id' => $organization->id,
                        'notification_key' => $key,
                        'locale' => $locale,
                        'created' => $isNew,
                        'changed_fields' => $changed,
                    ])
                    ->log('mail_template_updated');

                return $template;
            });
        });
    }
}
