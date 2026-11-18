<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\PrepareAuthorEdit;
use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SubmissionParticipant;
use App\Support\Auth\AuthorSession;
use App\Support\Submissions\SubmissionReference;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pantalla de edición: `GET .../my-submission/edit` (plan 006, CA-006-41, CA-006-42 y CA-006-48). La propuesta
 * sale de la sesión del autor (otro identificador, 404). Si ya no se puede editar vuelve a la propuesta con el aviso.
 */
final class ShowAuthorEditController
{
    public function __invoke(Organization $organization, Event $event, AuthorSession $session, ResolveAuthorSubmission $resolve, PrepareAuthorEdit $prepare): Response|RedirectResponse
    {
        $submission = $resolve->handle($session->current() ?? abort(404), $event);
        $locale = $submission->locale;
        app()->setLocale($locale);
        $route = ['organization' => $organization->slug, 'event' => $event->slug];

        $edit = $prepare->handle($submission, $event, $locale);

        if ($edit === null) {
            return to_route('author.submission.show', $route, 303)->withErrors(['edit' => __('author.submission_not_editable')]);
        }

        return Inertia::render('Author/Edit', [
            'event' => ['name' => $event->getTranslation('name', $locale)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'reference' => SubmissionReference::for($submission),
            'participants' => $submission->participants
                ->map(fn (SubmissionParticipant $participant): array => ['email' => $participant->email, 'isPrimary' => $participant->is_primary])
                ->values()
                ->all(),
            'edit' => $edit,
            'updateUrl' => route('author.update', $route, false),
            'showUrl' => route('author.submission.show', $route, false),
        ]);
    }
}
