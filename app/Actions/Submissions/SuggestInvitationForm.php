<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\CallStatus;
use App\Enums\FormDefinitionStatus;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\SubmissionType;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;

/**
 * Formulario que se sugiere al registrar un invitado de un tipo (plan 018, CA-018-4): el de la
 * convocatoria de ese tipo, ya publicada alguna vez (publicada, en pausa o cerrada), que abrió más
 * recientemente y cuyo formulario sigue publicado. Es solo una sugerencia: el organizador puede
 * elegir otro formulario publicado del evento. La autorización la hace `RegisterInvitedSpeaker`,
 * que compone esta Action.
 *
 * @throws MissingOrganizationContext sin contexto de organización
 */
final class SuggestInvitationForm
{
    public function handle(Event $event, SubmissionType $type): ?FormDefinition
    {
        $call = CallForSubmissions::query()
            ->where('event_id', $event->id)
            ->where('submission_type_id', $type->id)
            ->where('status', '!=', CallStatus::Draft->value)
            ->whereHas('formDefinition', fn ($form) => $form->where('status', FormDefinitionStatus::Published->value))
            ->with('formDefinition')
            ->orderByDesc('opens_at')
            ->first();

        return $call?->formDefinition;
    }
}
