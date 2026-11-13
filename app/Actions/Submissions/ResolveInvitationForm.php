<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Enums\FormDefinitionStatus;
use App\Exceptions\Domain\InvitationFormNotPublished;
use App\Models\Event;
use App\Models\FormDefinition;
use App\Models\SubmissionType;
use App\Support\Submissions\InvitationTarget;
use App\Support\Tenancy\Exceptions\MissingOrganizationContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Comprueba el tipo y el formulario con que se registra un invitado (plan 018, CA-018-5). Un
 * identificador de otra organización responde como inexistente (404, por el filtro de
 * organización); uno de otro evento de la misma organización, un tipo archivado o un formulario
 * sin publicar se rechazan. La autorización la hace `RegisterInvitedSpeaker`, que compone esta Action.
 *
 * @throws MissingOrganizationContext sin contexto de organización
 * @throws ModelNotFoundException si el tipo o el formulario son de otra organización
 * @throws ValidationException si el tipo está archivado o alguno es de otro evento
 * @throws InvitationFormNotPublished si el formulario no está publicado
 */
final class ResolveInvitationForm
{
    public function handle(Event $event, string $submissionTypeId, string $formDefinitionId): InvitationTarget
    {
        $type = SubmissionType::query()->findOrFail($submissionTypeId);
        $form = FormDefinition::query()->findOrFail($formDefinitionId);

        if ($type->event_id !== $event->id || $type->isArchived()) {
            throw ValidationException::withMessages(['submission_type' => __('submissions.invitation_type_invalid')]);
        }

        if ($form->event_id !== $event->id) {
            throw ValidationException::withMessages(['form_definition' => __('submissions.invitation_form_other_event')]);
        }

        if ($form->status !== FormDefinitionStatus::Published) {
            throw new InvitationFormNotPublished;
        }

        return new InvitationTarget($type, $form);
    }
}
