<?php

declare(strict_types=1);

namespace App\Support\Landings;

use App\Enums\LandingBlockType;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\EventLanding;
use App\Models\Organization;
use App\Models\Track;
use App\Support\Calls\CallLandingData;
use Closure;

/**
 * Resuelve los bloques `call_*` de la landing de una convocatoria para la página pública y para el lienzo del
 * editor (plan 033): textos en el idioma pedido (o el base del evento si falta), con el texto de relleno del
 * idioma donde el organizador no escribió nada, y los datos vivos de la convocatoria (ejes, formulario, límite
 * de personas, otras convocatorias) leídos AL MOSTRAR, nunca copiados en la landing. Las fechas, el estado y el
 * botón al formulario no viajan aquí: salen de la propia convocatoria (`call`) que recibe la página. La forma es
 * la de `PublicCall*Block` (`resources/js/types/landing.ts`).
 */
final class CallBlocksResolver
{
    /** @var array<string, mixed>|null */
    private ?array $live = null;

    /** @param  Closure(string): ?string  $imageUrl */
    public function __construct(
        private readonly Event $event,
        private readonly Organization $organization,
        private readonly CallForSubmissions $call,
        private readonly string $locale,
        private readonly Closure $imageUrl,
    ) {}

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    public function resolve(LandingBlockType $type, array $block): array
    {
        return match ($type) {
            LandingBlockType::CallHero => $this->hero($block),
            LandingBlockType::CallDates => $this->dates($block),
            LandingBlockType::CallAxes => $this->axes($block),
            LandingBlockType::CallRequirements => $this->requirements($block),
            LandingBlockType::CallSteps => $this->steps($block),
            LandingBlockType::CallPromos => $this->promos($block),
            default => [],
        };
    }

    /**
     * Los datos vivos que el lienzo del editor combina con el borrador: líneas de requisitos, formulario y anuncios.
     *
     * @return array{liveItems: list<string>, form: mixed, promos: mixed}
     */
    public function previewData(): array
    {
        $requirements = $this->requirements([]);

        return ['liveItems' => $requirements['items'], 'form' => $requirements['form'], 'promos' => $this->promos([])['promos']];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function hero(array $block): array
    {
        $imageId = is_array($block['image'] ?? null) ? ($block['image']['id'] ?? null) : null;
        $url = is_string($imageId) ? ($this->imageUrl)($imageId) : null;

        return [
            'title' => $this->text($block['title'] ?? null) ?: $this->callText('title'),
            'eventLine' => $this->text($block['eventLine'] ?? null) ?: (string) $this->event->getTranslation('name', $this->locale),
            'lede' => $this->text($block['lede'] ?? null) ?: $this->callText('description'),
            'notice' => $this->nullable($this->text($block['notice'] ?? null)),
            'imageUrl' => $url,
            'imageAlt' => $url === null ? null : $this->nullable($this->text($block['imageAlt'] ?? null)),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function dates(array $block): array
    {
        $venue = $this->text($block['venueName'] ?? null);

        return [
            'timezoneLabel' => $this->nullable($this->text($block['timezoneLabel'] ?? null)),
            'resultsLabel' => $this->nullable($this->text($block['resultsLabel'] ?? null)),
            'venue' => $venue === '' ? null : ['name' => $venue, 'note' => $this->text($block['venueNote'] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function axes(array $block): array
    {
        return [
            'title' => $this->text($block['title'] ?? null) ?: $this->template('axes_title'),
            'intro' => $this->text($block['intro'] ?? null) ?: $this->template('axes_intro'),
            'axes' => $this->event->tracks()->active()->orderBy('position')->get()
                ->map(fn (Track $track): array => [
                    'name' => $this->translated($track, 'name'),
                    'description' => $this->nullable($this->translated($track, 'description')),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function requirements(array $block): array
    {
        $live = $this->liveData();
        $items = $this->liveRequirements($live);

        foreach ((array) ($block['items'] ?? []) as $item) {
            $text = $this->text(((array) $item)['text'] ?? null);

            if ($text !== '') {
                $items[] = $text;
            }
        }

        /** @var list<array{name: string, isRequired: bool}> $proposal */
        $proposal = $live['formProposal'];
        /** @var list<array{name: string, isRequired: bool}> $participant */
        $participant = $live['formParticipant'];
        $groups = array_values(array_filter([
            ['title' => $this->live('form_proposal'), 'fields' => $proposal],
            ['title' => $this->live('form_participant'), 'fields' => $participant],
        ], static fn (array $group): bool => $group['fields'] !== []));

        return [
            'title' => $this->text($block['title'] ?? null) ?: $this->template('requirements_title'),
            'intro' => $this->nullable($this->text($block['intro'] ?? null) ?: $this->template('requirements_intro')),
            'items' => $items,
            'buttonLabel' => $this->text($block['buttonLabel'] ?? null) ?: $this->template('requirements_button'),
            'form' => $groups === [] ? null : [
                'title' => $this->live('form_title'),
                'requiredNote' => $this->live('form_required'),
                'groups' => $groups,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function steps(array $block): array
    {
        $steps = [];

        foreach ((array) ($block['items'] ?? []) as $item) {
            $item = (array) $item;
            $steps[] = [
                'icon' => (string) ($item['icon'] ?? 'doc'),
                'title' => $this->text($item['title'] ?? null),
                'text' => $this->text($item['text'] ?? null),
            ];
        }

        return ['title' => $this->text($block['title'] ?? null) ?: $this->template('steps_title'), 'steps' => $steps];
    }

    /**
     * Anuncios de las demás convocatorias del evento. La portada de cada uno es la imagen de la portada de SU
     * landing, si esa landing está publicada y la tiene.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function promos(array $block): array
    {
        $button = $this->text($block['buttonLabel'] ?? null) ?: $this->template('promo_button');
        $promos = [];

        /** @var list<array{slug: string, title: string, description: ?string, url: string}> $others */
        $others = $this->liveData()['otherCalls'];

        foreach ($others as $other) {
            $promos[] = [
                'url' => $other['url'],
                'title' => $other['title'],
                'text' => $other['description'] ?? $this->live('promo_fallback'),
                'button' => $button,
                'coverUrl' => $this->coverOf($other['slug']),
            ];
        }

        return ['promos' => $promos];
    }

    /** La portada propia de otra convocatoria: la imagen de su `call_hero` publicado, o `null`. */
    private function coverOf(string $slug): ?string
    {
        $other = CallForSubmissions::query()->where('event_id', $this->event->id)->where('slug', $slug)->first();
        $landing = $other === null ? null : EventLanding::currentPublishedForCall($other);

        if ($landing === null) {
            return null;
        }

        foreach ($landing->published_blocks ?? [] as $published) {
            if (($published['type'] ?? null) === LandingBlockType::CallHero->value && is_array($published['image'] ?? null)) {
                $id = $published['image']['id'] ?? null;

                return is_string($id) ? LandingImageUrls::forPublic($this->event, $this->organization, $landing)($id) : null;
            }
        }

        return null;
    }

    /**
     * Líneas de requisitos que salen de los datos reales de la convocatoria (límite de personas y ejes).
     *
     * @param  array<string, mixed>  $live
     * @return list<string>
     */
    private function liveRequirements(array $live): array
    {
        $max = (int) $live['maxParticipants'];
        $min = (int) $live['minParticipants'];
        $items = [$max > 1 ? $this->live('team', ['max' => $max]) : $this->live('individual')];

        if ($min > 1) {
            $items[] = $this->live('minimum', ['min' => $min]);
        }

        if ($this->event->tracks()->active()->exists()) {
            $items[] = $this->live('one_axis');
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function liveData(): array
    {
        return $this->live ??= CallLandingData::from($this->call, $this->event, $this->organization, $this->locale);
    }

    /** @param  array<string, int|string>  $replace */
    private function live(string $key, array $replace = []): string
    {
        return (string) __("landings.call_live.{$key}", $replace, $this->locale);
    }

    private function template(string $key): string
    {
        return (string) __("landings.call_template.{$key}", [], $this->locale);
    }

    /** Texto de la convocatoria en el idioma pedido; si falta, en el idioma base del evento. */
    private function callText(string $attribute): string
    {
        $text = (string) $this->call->getTranslation($attribute, $this->locale, false);

        return $text !== '' ? $text : (string) $this->call->getTranslation($attribute, $this->event->default_locale, false);
    }

    private function translated(Track $track, string $attribute): string
    {
        $text = (string) $track->getTranslation($attribute, $this->locale, false);

        return $text !== '' ? $text : (string) $track->getTranslation($attribute, $this->event->default_locale, false);
    }

    /** Texto por idioma en el idioma pedido; si falta, en el idioma base del evento. */
    private function text(mixed $localized): string
    {
        if (! is_array($localized)) {
            return '';
        }

        $text = $localized[$this->locale] ?? null;

        if (! is_string($text) || $text === '') {
            $text = $localized[$this->event->default_locale] ?? '';
        }

        return is_string($text) ? $text : '';
    }

    private function nullable(string $text): ?string
    {
        return $text === '' ? null : $text;
    }
}
