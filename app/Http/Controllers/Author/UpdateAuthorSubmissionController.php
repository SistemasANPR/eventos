<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Actions\Submissions\UpdateSubmissionByAuthor;
use App\Exceptions\Domain\CallNotOpen;
use App\Exceptions\Domain\CallNotReady;
use App\Exceptions\Domain\FormOutdated;
use App\Exceptions\Domain\SubmissionNotEditable;
use App\Exceptions\Domain\SubmissionVersionConflict;
use App\Http\Requests\Submissions\UpdateSubmissionRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use Illuminate\Http\RedirectResponse;

/**
 * Guardar la edición: `PATCH .../my-submission/edit` (plan 006, CA-006-41 a 45). La propuesta sale de la sesión
 * del autor. Los rechazos de dominio (convocatoria cerrada, formulario cambiado, otra edición antes, ya no
 * editable) vuelven a la pantalla con el mensaje en `errors.edit`; los de validación, con sus campos. Un fallo
 * inesperado se propaga.
 */
final class UpdateAuthorSubmissionController
{
    public function __invoke(
        UpdateSubmissionRequest $request,
        Organization $organization,
        Event $event,
        AuthorSession $session,
        ResolveAuthorSubmission $resolve,
        UpdateSubmissionByAuthor $update,
    ): RedirectResponse {
        $submission = $resolve->handle($session->current() ?? abort(404), $event);
        $route = ['organization' => $organization->slug, 'event' => $event->slug];
        app()->setLocale($submission->locale);

        try {
            $update->handle($submission, $request->validated());
        } catch (CallNotOpen|CallNotReady|FormOutdated|SubmissionNotEditable|SubmissionVersionConflict $exception) {
            return to_route('author.edit', $route, 303)->withErrors(['edit' => $exception->getMessage()]);
        }

        $request->session()->put('author.updated', true);

        return to_route('author.submission.show', $route, 303);
    }
}
