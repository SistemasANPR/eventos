<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Enums\CallState;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\SubmissionType;
use App\Models\Track;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lo único que la página pública de una convocatoria recibe (CA-004-21): campos
 * explícitos, sin identificadores internos ni datos del equipo. Las fechas viajan
 * en UTC (ISO 8601); el cliente las formatea con `Intl` en la zona del evento.
 */
final class PublicCallData
{
    /**
     * @return array{title: string, description: ?string, submissionType: string, state: string, opensAt: string, closesAt: string, resultsAt?: string, timezone: string, locale: string, locales: list<string>, tracks: list<array{name: string, description: ?string}>}
     */
    public static function from(CallForSubmissions $call, Event $event, CallState $state, string $locale): array
    {
        $data = [
            'title' => self::text($call, 'title', $locale, $event->default_locale),
            'description' => self::text($call, 'description', $locale, $event->default_locale) ?: null,
            'submissionType' => self::text($call->submissionType, 'name', $locale, $event->default_locale),
            'state' => $state->value,
            'opensAt' => self::utc($call->opens_at),
            'closesAt' => self::utc($call->closes_at),
            'timezone' => $event->timezone,
            'locale' => $locale,
            'locales' => $call->locales,
            'tracks' => self::tracks($event, $locale),
        ];

        if ($call->results_at !== null) {
            $data['resultsAt'] = self::utc($call->results_at);
        }

        return $data;
    }

    /**
     * Idioma de la página: `?lang=` si la CONVOCATORIA lo ofrece; si no, el mejor
     * según `Accept-Language` entre sus idiomas; si no, el idioma base del evento
     * (si la convocatoria lo tiene) o el primero de la convocatoria.
     */
    public static function resolveLocale(Request $request, CallForSubmissions $call, Event $event): string
    {
        $requested = $request->query('lang');

        if (is_string($requested) && in_array($requested, $call->locales, true)) {
            return $requested;
        }

        return $request->getPreferredLanguage($call->locales)
            ?? (in_array($event->default_locale, $call->locales, true) ? $event->default_locale : $call->locales[0]);
    }

    /** @return list<array{name: string, description: ?string}> */
    private static function tracks(Event $event, string $locale): array
    {
        return $event->tracks()->active()->orderBy('position')->get()
            ->map(fn (Track $track): array => [
                'name' => self::text($track, 'name', $locale, $event->default_locale),
                'description' => self::text($track, 'description', $locale, $event->default_locale) ?: null,
            ])
            ->values()
            ->all();
    }

    /** Texto traducible en el idioma pedido; si falta, en el idioma base del evento. */
    private static function text(CallForSubmissions|SubmissionType|Track $model, string $attribute, string $locale, string $fallbackLocale): string
    {
        $text = (string) $model->getTranslation($attribute, $locale, false);

        return $text !== '' ? $text : (string) $model->getTranslation($attribute, $fallbackLocale, false);
    }

    private static function utc(Carbon $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
