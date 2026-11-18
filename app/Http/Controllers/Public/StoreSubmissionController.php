<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Actions\Submissions\SubmitSubmission;
use App\Actions\Submissions\VerifyTurnstile;
use App\Exceptions\Domain\CallNotOpen;
use App\Exceptions\Domain\CallNotReady;
use App\Exceptions\Domain\FormOutdated;
use App\Exceptions\Domain\IdempotencyPayloadMismatch;
use App\Exceptions\Domain\TurnstileInvalid;
use App\Exceptions\Domain\TurnstileUnavailable;
use App\Http\Requests\Submissions\StoreSubmissionRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Submissions\PublicFormThrottle;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Envío público de una propuesta: `POST /{organization:slug}/events/{event:slug}/call/{call:slug}/submit`.
 *
 * Orden: validar (Form Request) → límite de peticiones → Turnstile (solo aquí, en el envío final) →
 * `SubmitSubmission`. Éxito: redirección a la confirmación, sin datos personales en la URL
 * (CA-006-57); un reintento idempotente da la misma confirmación.
 *
 * Los errores de validación (422) usan el mecanismo de Inertia: vuelven a la página con los errores
 * por campo. Los demás fallos (convocatoria cerrada, formulario cambiado, límite, servicio
 * anti-robots) responden con un cuerpo `{code, message}` y su estado HTTP: el cliente los muestra
 * sin salir de la página, de modo que lo que la persona escribió se conserva (CA-006-56).
 */
final class StoreSubmissionController
{
    public function __invoke(
        StoreSubmissionRequest $request,
        Organization $organization,
        Event $event,
        CallForSubmissions $call,
        PublicFormThrottle $throttle,
        VerifyTurnstile $turnstile,
        SubmitSubmission $submit,
    ): RedirectResponse|JsonResponse {
        // El idioma del envío ya está validado contra los de la convocatoria.
        app()->setLocale($request->string('locale')->toString());

        try {
            $throttle->hitSubmission((string) $request->ip(), $request->primaryEmail());
            $turnstile->handle($request->string('turnstile_token')->toString(), $request->ip());

            $submit->handle($call, $request->validated(), $request->string('upload_token')->toString());
        } catch (ThrottleRequestsException $exception) {
            return $this->failure('TOO_MANY_REQUESTS', __('submission_form.too_many_requests'), 429, $exception->getHeaders());
        } catch (TurnstileInvalid $exception) {
            throw ValidationException::withMessages(['turnstile_token' => $exception->getMessage()]);
        } catch (TurnstileUnavailable $exception) {
            return $this->failure(TurnstileUnavailable::CODE, $exception->getMessage(), 503, $exception->getHeaders());
        } catch (CallNotOpen $exception) {
            return $this->failure(CallNotOpen::CODE, $exception->getMessage(), 409);
        } catch (CallNotReady $exception) {
            return $this->failure(CallNotReady::CODE, $exception->getMessage(), 409);
        } catch (FormOutdated $exception) {
            return $this->failure(FormOutdated::CODE, $exception->getMessage(), 409);
        } catch (IdempotencyPayloadMismatch $exception) {
            return $this->failure(IdempotencyPayloadMismatch::CODE, $exception->getMessage(), 409);
        }

        // Dato de sesión que la confirmación exige: sin él, `/submitted` redirige a la convocatoria.
        $request->session()->put(ShowSubmissionConfirmationController::sessionKey($call), true);

        return to_route('calls.submitted', [
            'organization' => $organization->slug,
            'event' => $event->slug,
            'call' => $call->slug,
            // El idioma del envío (no es un dato personal) mantiene la confirmación en el mismo idioma.
            'lang' => app()->getLocale(),
        ], 303);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function failure(string $code, string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message], $status, $headers);
    }
}
