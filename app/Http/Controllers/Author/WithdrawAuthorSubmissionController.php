<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Actions\Submissions\ResolveAuthorSubmission;
use App\Actions\Submissions\WithdrawSubmission;
use App\Exceptions\Domain\SubmissionNotWithdrawable;
use App\Http\Requests\Submissions\WithdrawSubmissionRequest;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Auth\AuthorSession;
use Illuminate\Http\RedirectResponse;

/**
 * Retirar la propuesta: `POST .../my-submission/withdraw` (plan 006, CA-006-46 a 48). Solo POST con CSRF
 * (un GET responde 405). La propuesta sale de la sesión del autor. Repetir el retiro es inofensivo
 * (idempotente) y vuelve a la misma página.
 */
final class WithdrawAuthorSubmissionController
{
    public function __invoke(
        WithdrawSubmissionRequest $request,
        Organization $organization,
        Event $event,
        AuthorSession $session,
        ResolveAuthorSubmission $resolve,
        WithdrawSubmission $withdraw,
    ): RedirectResponse {
        $submission = $resolve->handle($session->current() ?? abort(404), $event);

        try {
            $withdraw->handle($submission);
        } catch (SubmissionNotWithdrawable $exception) {
            return to_route('author.submission.show', ['organization' => $organization->slug, 'event' => $event->slug], 303)
                ->withErrors(['withdraw' => $exception->getMessage()]);
        }

        return to_route('author.submission.show', ['organization' => $organization->slug, 'event' => $event->slug], 303);
    }
}
