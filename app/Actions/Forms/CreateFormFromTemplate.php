<?php

declare(strict_types=1);

namespace App\Actions\Forms;

use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\User;
use App\Support\Forms\FormTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Crea en un evento un formulario propio a partir de una plantilla del sistema (plan 005,
 * H-005-5, CA-005-35). La copia nace en borrador, con campos y claves propios: editarla
 * nunca altera la plantilla, que vive en código y es de solo lectura (CA-005-37). Pasa por
 * `CreateFormDefinition`, así que hereda su autorización, validación y auditoría.
 *
 * @throws AuthorizationException si quien crea no tiene `forms.manage` en la organización del evento
 * @throws ValidationException si la plantilla no existe o no cabe en los idiomas del evento
 */
final class CreateFormFromTemplate
{
    public function __construct(private readonly CreateFormDefinition $createForm) {}

    public function handle(User $actor, Event $event, string $template): FormDefinition
    {
        if (! in_array($template, FormTemplates::names(), true)) {
            throw ValidationException::withMessages(['template' => __('forms.template_unknown')]);
        }

        $definition = FormTemplates::definition($template);
        $name = $definition['name'][$event->default_locale] ?? $definition['name']['es'];

        return $this->createForm->handle($actor, $event, ['name' => $name, 'fields' => $definition['fields']]);
    }
}
