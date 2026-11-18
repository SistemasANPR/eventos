<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\FormDefinitionStatus;
use App\Exceptions\Domain\CallNotReady;
use App\Models\CallForSubmissions;
use App\Models\FormDefinition;
use App\Support\Forms\LockedFormDefinition;

/**
 * El formulario PUBLICADO de una convocatoria, cargado con bloqueo (`LockedFormDefinition`): una edición
 * simultánea del formulario espera a que termine la transacción de quien lo usa (plan 006, CA-006-12).
 * Lo usan el envío y la edición por el autor. Debe llamarse dentro de una transacción.
 *
 * @throws CallNotReady sin formulario, sin publicar o de otro evento
 */
final class LoadPublishedForm
{
    public function __construct(private readonly LockedFormDefinition $lockedForm) {}

    public function handle(CallForSubmissions $call): FormDefinition
    {
        if ($call->form_definition_id === null) {
            throw new CallNotReady;
        }

        $form = $this->lockedForm->handle($call->form_definition_id);

        if ($form->status !== FormDefinitionStatus::Published || $form->event_id !== $call->event_id) {
            throw new CallNotReady;
        }

        return $form;
    }
}
