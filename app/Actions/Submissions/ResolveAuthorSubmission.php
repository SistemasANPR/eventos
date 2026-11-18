<?php

declare(strict_types=1);

namespace App\Actions\Submissions;

use App\Models\Event;
use App\Models\Submission;
use App\Support\Auth\AuthorIdentity;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * La propuesta a la que da acceso la sesión del autor (plan 006, CA-006-38). La sesión nombra UNA
 * propuesta de UNA organización: si la ruta es de otra organización, de otro evento o la propuesta
 * ya no existe, responde 404 sin distinguir el caso. El identificador nunca viene del cliente.
 *
 * @throws ModelNotFoundException
 */
final class ResolveAuthorSubmission
{
    public function handle(AuthorIdentity $author, Event $event): Submission
    {
        if ($author->organizationId !== CurrentOrganization::id()) {
            throw (new ModelNotFoundException)->setModel(Submission::class);
        }

        return Submission::query()
            ->where('event_id', $event->id)
            ->with(['participants', 'track'])
            ->findOrFail($author->submissionId);
    }
}
