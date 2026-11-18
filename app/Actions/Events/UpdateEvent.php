<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Exceptions\Domain\EventLocaleInUse;
use App\Http\Requests\Events\StoreEventRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\User;
use App\Support\Auth\AuthorizesInOrganization;
use App\Support\Events\EventAttributes;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edita un evento (plan 003, CA-003-3, -5, -6 y -9). Se ejecuta en la
 * organización DEL EVENTO: quien no pertenece a ella no puede editarlo.
 *
 * Cambiar el `slug` de un evento publicado rompe los enlaces ya difundidos:
 * exige confirmación explícita y queda auditado.
 *
 * Quitar un idioma que usa una convocatoria o el aviso de privacidad publicado también exige
 * confirmación (plan 026, DA-1); nada se borra y la auditoría registra los idiomas quitados y agregados.
 *
 * @throws AuthorizationException si quien edita no tiene `events.manage` en la organización del evento
 * @throws ValidationException si los datos no son válidos o falta confirmar el cambio de `slug`
 * @throws EventLocaleInUse si se quita un idioma en uso y falta `confirmLocaleRemoval`
 */
final class UpdateEvent
{
    use AuthorizesInOrganization;

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, Event $event, array $data, bool $confirmSlugChange = false, bool $confirmLocaleRemoval = false): Event
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($actor, $event, $data, $confirmSlugChange, $confirmLocaleRemoval, $organization): Event {
            $this->authorizeActor($actor, 'update', $event);

            /** @var list<string> $locales */
            $locales = (array) ($data['locales'] ?? []);
            $validated = Validator::make($data, StoreEventRequest::rulesFor($organization->id, $locales, $event->id))->validate();

            $previousSlug = $event->slug;
            $slugChanges = $validated['slug'] !== $previousSlug;

            if ($slugChanges && $event->isPublished() && ! $confirmSlugChange) {
                throw ValidationException::withMessages(['slug' => __('events.slug_change_needs_confirmation')]);
            }

            $removed = array_values(array_diff($event->offeredLocales(), $validated['locales']));
            $added = array_values(array_diff($validated['locales'], $event->offeredLocales()));

            if ($removed !== [] && ! $confirmLocaleRemoval) {
                $affected = $this->affectedBy($event, $removed);

                if ($affected !== []) {
                    throw new EventLocaleInUse($removed, $affected);
                }
            }

            return DB::transaction(function () use ($actor, $event, $validated, $slugChanges, $previousSlug, $removed, $added): Event {
                $event->fill(EventAttributes::fromValidated($validated))->save();

                activity()
                    ->causedBy($actor)
                    ->performedOn($event)
                    ->event('event_updated')
                    ->withProperties(['organization_id' => $event->organization_id, 'removed_locales' => $removed, 'added_locales' => $added])
                    ->log('event_updated');

                if ($slugChanges && $event->isPublished()) {
                    activity()
                        ->causedBy($actor)
                        ->performedOn($event)
                        ->event('event_slug_changed')
                        ->withProperties(['organization_id' => $event->organization_id, 'from' => $previousSlug, 'to' => $event->slug])
                        ->log('event_slug_changed');
                }

                return $event;
            });
        });
    }

    /**
     * Lo que usa alguno de los idiomas que se quitan: convocatorias y el aviso de privacidad publicado vigente.
     *
     * @param  list<string>  $removed
     * @return list<string>
     */
    private function affectedBy(Event $event, array $removed): array
    {
        $affected = [];

        $calls = CallForSubmissions::query()->where('event_id', $event->id)->orderBy('created_at')->get();

        foreach ($calls as $call) {
            if (array_intersect($call->locales, $removed) !== []) {
                $affected[] = 'convocatoria «'.$call->getTranslation('title', $event->default_locale, true).'»';
            }
        }

        $notice = PrivacyNotice::currentPublished($event);

        if ($notice !== null && array_intersect(array_keys($notice->getTranslations('body')), $removed) !== []) {
            $affected[] = 'aviso de privacidad v'.$notice->version;
        }

        return $affected;
    }
}
