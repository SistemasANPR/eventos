<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use App\Support\Submissions\AuthorSubmissionData;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La propuesta del autor: `GET .../my-submission` (plan 006, CA-006-38 y CA-006-40). Exige la sesión del guard
 * `author` (middleware `AuthenticateAuthor`). La propuesta sale de la SESIÓN, nunca de un identificador de la
 * petición; si la ruta es de otra organización o evento, 404. Solo se entregan campos del autor.
 */
final class ShowAuthorSubmissionController
{
    public function __invoke(Organization $organization, Event $event, AuthorSession $session, ResolveAuthorSubmission $resolve): Response
    {
        $author = $session->current() ?? abort(404);

        $submission = $resolve->handle($author, $event);
        $locale = $submission->locale;
        app()->setLocale($locale);

        return Inertia::render('Author/Show', [
            'event' => ['name' => $event->getTranslation('name', $locale)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'submission' => AuthorSubmissionData::from($submission, $event, $locale),
            'sessionMinutes' => (int) config('submissions.author.session_minutes'),
            'eventUrl' => route('events.show', ['organization' => $organization->slug, 'event' => $event->slug], false)."?lang={$locale}",
        ]);
    }
}
