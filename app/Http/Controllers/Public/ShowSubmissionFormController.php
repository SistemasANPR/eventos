<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Submissions\PrepareSubmissionForm;
use App\Enums\CallState;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Calls\PublicCallData;
use App\Support\Calls\ResolveCallState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formulario público de envío: `GET /{organization:slug}/events/{event:slug}/call/{call:slug}/submit`.
 * Con la convocatoria abierta y lista muestra el formulario; en cualquier otro caso (próxima, en
 * pausa, cerrada o no lista) muestra el estado SIN formulario (CA-006-55). Un borrador, otro evento
 * u otra organización responden 404 como la página de la convocatoria. La respuesta no se guarda
 * en caché (`PreventCaching`): lleva claves emitidas a la sesión del visitante.
 */
final class ShowSubmissionFormController
{
    public function __invoke(Request $request, Organization $organization, Event $event, CallForSubmissions $call, PrepareSubmissionForm $prepare): Response
    {
        abort_unless($event->isPublished(), 404);

        $state = ResolveCallState::for($call);

        abort_if($state === null, 404);

        $locale = PublicCallData::resolveLocale($request, $call, $event);
        app()->setLocale($locale);

        $route = ['organization' => $organization->slug, 'event' => $event->slug];
        $callRoute = $route + ['call' => $call->slug];

        $form = $state === CallState::Open
            ? $prepare->handle(
                $call,
                $event,
                $locale,
                route('events.privacy', $route, false),
                route('calls.files.store', $route + ['call' => $call->id], false),
            )
            : null;

        return Inertia::render('Calls/Submit', [
            'call' => PublicCallData::from($call, $event, $state, $locale),
            'event' => ['name' => $event->getTranslation('name', $locale)],
            'organization' => ['name' => $organization->name, 'slug' => $organization->slug],
            'callUrl' => route('calls.show', $callRoute, false)."?lang={$locale}",
            'submitUrl' => route('calls.submit.store', $callRoute, false),
            // `null` con la convocatoria abierta significa «no lista» (sin formulario o sin aviso).
            'form' => $form,
        ]);
    }
}
