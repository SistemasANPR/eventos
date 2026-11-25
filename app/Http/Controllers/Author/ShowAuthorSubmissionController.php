<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\PrepareAuthorEdit;
use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Enums\SubmissionStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use App\Support\Events\PublicPageAvailability;
use App\Support\Submissions\AuthorSubmissionData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La propuesta del autor: `GET .../my-submission` (plan 006, CA-006-38 y CA-006-40). Exige la sesión del guard
 * `author` (middleware `AuthenticateAuthor`). La propuesta sale de la SESIÓN, nunca de un identificador de la
 * petición; si la ruta es de otra organización o evento, 404. Solo se entregan campos del autor.
 */
final class ShowAuthorSubmissionController
{
    public function __invoke(Organization $organization, Event $event, AuthorSession $session, ResolveAuthorSubmission $resolve, PrepareAuthorEdit $edit, Request $request): Response
    {
        $author = $session->current() ?? abort(404);

        $submission = $resolve->handle($author, $event);
        $locale = $submission->locale;
        app()->setLocale($locale);

        return Inertia::render('Author/Show', [
            'event' => ['name' => $event->getTranslation('name', $locale)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'submission' => AuthorSubmissionData::from($submission, $event, $locale),
            // Solo se ofrece retirar mientras la propuesta sigue «enviada» (DA-7); el servidor lo vuelve a comprobar.
            'withdrawUrl' => $submission->status === SubmissionStatus::Submitted
                ? route('author.withdraw', ['organization' => $organization->slug, 'event' => $event->slug], false)
                : null,
            // Solo se ofrece editar con la convocatoria abierta; el servidor lo vuelve a comprobar al guardar.
            'editUrl' => $edit->isEditable($submission)
                ? route('author.edit', ['organization' => $organization->slug, 'event' => $event->slug], false)
                : null,
            'wasUpdated' => (bool) $request->session()->pull('author.updated', false),
            'sessionMinutes' => (int) config('submissions.author.session_minutes'),
            // Sin página pública del evento (archivado) no se ofrece el enlace de vuelta (plan 030, CA-030-6).
            'eventUrl' => PublicPageAvailability::eventUrl($event, $organization, $locale),
        ]);
    }
}
