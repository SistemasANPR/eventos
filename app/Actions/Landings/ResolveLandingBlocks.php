<?php

declare(strict_types=1);

namespace App\Actions\Landings;

use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Enums\LandingBlockType;
use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Track;
use App\Support\Calls\PublicCallData;
use App\Support\Calls\ResolveCallState;
use App\Support\Events\PublicEventData;
use App\Support\Html\RichTextSanitizer;
use Closure;
use Illuminate\Support\Arr;

/**
 * Convierte los bloques guardados de la landing en lo que recibe la página pública (plan 022, DA-3, DA-5,
 * CA-022-21, 24 a 27): textos en el idioma pedido (o el idioma base del evento si falta), URLs de las
 * imágenes ya construidas y los datos vivos del evento, las convocatorias y los ejes leídos AL MOSTRAR,
 * nunca copiados en la landing. No devuelve el borrador, ni identificadores de organización, ni datos
 * del equipo; las convocatorias no llevan su identificador. La forma es la de `PublicLandingBlock`
 * (`resources/js/types/landing.ts`):
 *
 * - `hero`: `title`, `subtitle`, `imageUrl`, `imageAlt`, `button: {label, url}|null`, `tone`, `align`.
 * - `rich_text`: `html` (saneado de nuevo) y `width`.
 * - `event_info`: `name`, `description`, `startsAt`, `endsAt`, `timezone`, `timeFormat`, `venueName`, `city`.
 * - `calls`: `title` y `calls: [{title, submissionType, state, opensAt, closesAt, timezone, timeFormat,
 *   registerUrl}]`. Solo las convocatorias PUBLICADAS del evento (nunca borradores), por fecha de apertura.
 *   `registerUrl` es la URL de la PÁGINA de la convocatoria; es nulo si no está abierta: el cliente muestra el botón bloqueado (DA-17).
 * - `tracks`: `title` y `tracks: [{name, description}]` (ejes activos).
 * - `faq`: `title` e `items: [{question, answerHtml}]`.
 * - `image_gallery`: `title` e `images: [{url, alt}]`.
 * - `spacer`: `size`.
 * - `columns`: `columns: [PublicColumn, PublicColumn]`, cada una `{kind: 'text', html}` o `{kind: 'image', url, alt}`.
 *
 * Las imágenes que ya no existen (borradas) se omiten en la galería y como fondo, sin romper la página.
 */
final class ResolveLandingBlocks
{
    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  Closure(string): ?string  $imageUrl  URL de una imagen por su id, o null si no está disponible
     * @return list<array<string, mixed>>
     */
    public function handle(Event $event, Organization $organization, array $blocks, string $locale, Closure $imageUrl): array
    {
        $resolved = [];
        $live = null;

        foreach ($blocks as $block) {
            $type = LandingBlockType::tryFrom((string) ($block['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $base = ['id' => (string) $block['id'], 'type' => $type->value];

            $resolved[] = $base + match ($type) {
                LandingBlockType::Hero => $this->hero($event, $block, $locale, $imageUrl),
                LandingBlockType::RichText => [
                    'html' => RichTextSanitizer::clean($this->text($event, $block['html'] ?? null, $locale)),
                    'width' => (string) ($block['width'] ?? 'narrow'),
                ],
                LandingBlockType::EventInfo => $this->eventInfo($event, $organization, $locale),
                LandingBlockType::Calls => [
                    'title' => $this->nullable($this->text($event, $block['title'] ?? null, $locale)),
                    'calls' => $live['calls'] ??= $this->calls($event, $organization, $locale),
                ],
                LandingBlockType::Tracks => [
                    'title' => $this->nullable($this->text($event, $block['title'] ?? null, $locale)),
                    'tracks' => $live['tracks'] ??= $this->tracks($event, $locale),
                ],
                LandingBlockType::Faq => [
                    'title' => $this->nullable($this->text($event, $block['title'] ?? null, $locale)),
                    'items' => $this->faqItems($event, $block, $locale),
                ],
                LandingBlockType::ImageGallery => [
                    'title' => $this->nullable($this->text($event, $block['title'] ?? null, $locale)),
                    'images' => $this->gallery($event, $block, $locale, $imageUrl),
                ],
                LandingBlockType::Spacer => ['size' => (string) ($block['size'] ?? 'md')],
                LandingBlockType::Columns => ['columns' => $this->columns($event, $block, $locale, $imageUrl)],
            };
        }

        return $resolved;
    }

    /**
     * Los datos vivos con que el lienzo del editor dibuja los bloques que no guardan copia (`LandingPreviewData`).
     *
     * @return array{event: array<string, mixed>, calls: list<array<string, mixed>>, tracks: list<array{name: string, description: ?string}>}
     */
    public function preview(Event $event, Organization $organization, string $locale): array
    {
        return [
            'event' => $this->eventInfo($event, $organization, $locale),
            'calls' => $this->calls($event, $organization, $locale),
            'tracks' => $this->tracks($event, $locale),
        ];
    }

    /**
     * Título, descripción e imagen para el `<title>`, la descripción y `og:image` de la página (CA-022-28).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  Closure(string): ?string  $imageUrl
     * @return array{title: string, description: ?string, imageUrl: ?string}
     */
    public function meta(Event $event, array $blocks, string $locale, Closure $imageUrl): array
    {
        $hero = null;

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === LandingBlockType::Hero->value) {
                $hero = $block;
                break;
            }
        }

        $title = $hero === null ? '' : $this->text($event, $hero['title'] ?? null, $locale);
        $subtitle = $hero === null ? '' : $this->text($event, $hero['subtitle'] ?? null, $locale);
        $imageId = $hero !== null && is_array($hero['image'] ?? null) ? ($hero['image']['id'] ?? null) : null;

        return [
            'title' => $title !== '' ? $title : $event->getTranslation('name', $locale),
            'description' => $subtitle !== '' ? $subtitle : ($event->getTranslation('description', $locale, false) ?: null),
            'imageUrl' => is_string($imageId) ? $imageUrl($imageId) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Closure(string): ?string  $imageUrl
     * @return array<string, mixed>
     */
    private function hero(Event $event, array $block, string $locale, Closure $imageUrl): array
    {
        $url = is_string($block['buttonUrl'] ?? null) && $block['buttonUrl'] !== '' ? $block['buttonUrl'] : null;
        $label = $this->text($event, $block['buttonLabel'] ?? null, $locale);
        $imageId = is_array($block['image'] ?? null) ? ($block['image']['id'] ?? null) : null;

        return [
            'title' => $this->text($event, $block['title'] ?? null, $locale),
            'subtitle' => $this->nullable($this->text($event, $block['subtitle'] ?? null, $locale)),
            'imageUrl' => is_string($imageId) ? $imageUrl($imageId) : null,
            'imageAlt' => $this->nullable($this->text($event, $block['imageAlt'] ?? null, $locale)),
            'button' => $url !== null && $label !== '' ? ['label' => $label, 'url' => $url] : null,
            'tone' => (string) ($block['tone'] ?? 'accent'),
            'align' => (string) ($block['align'] ?? 'center'),
        ];
    }

    /** @return array<string, mixed> */
    private function eventInfo(Event $event, Organization $organization, string $locale): array
    {
        return Arr::only(PublicEventData::from($event, $organization, $locale), ['name', 'description', 'startsAt', 'endsAt', 'timezone', 'timeFormat', 'venueName', 'city']);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<array{question: string, answerHtml: string}>
     */
    private function faqItems(Event $event, array $block, string $locale): array
    {
        $items = [];

        foreach ((array) ($block['items'] ?? []) as $item) {
            $items[] = [
                'question' => $this->text($event, $item['question'] ?? null, $locale),
                'answerHtml' => RichTextSanitizer::clean($this->text($event, $item['answer'] ?? null, $locale)),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Closure(string): ?string  $imageUrl
     * @return list<array{url: string, alt: string}>
     */
    private function gallery(Event $event, array $block, string $locale, Closure $imageUrl): array
    {
        $images = [];

        foreach ((array) ($block['images'] ?? []) as $item) {
            $url = $this->imageUrlOf($item['image'] ?? null, $imageUrl);

            if ($url !== null) {
                $images[] = ['url' => $url, 'alt' => $this->text($event, $item['alt'] ?? null, $locale)];
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  Closure(string): ?string  $imageUrl
     * @return list<array<string, mixed>>
     */
    private function columns(Event $event, array $block, string $locale, Closure $imageUrl): array
    {
        $columns = [];

        foreach ((array) ($block['columns'] ?? []) as $column) {
            $url = ($column['kind'] ?? 'text') === 'image' ? $this->imageUrlOf($column['image'] ?? null, $imageUrl) : null;

            // Una imagen que ya no existe deja la columna vacía, sin romper la página.
            $columns[] = ($column['kind'] ?? 'text') === 'image' && $url !== null
                ? ['kind' => 'image', 'url' => $url, 'alt' => $this->text($event, $column['alt'] ?? null, $locale)]
                : ['kind' => 'text', 'html' => RichTextSanitizer::clean($this->text($event, $column['html'] ?? null, $locale))];
        }

        return $columns;
    }

    /** @param  Closure(string): ?string  $imageUrl */
    private function imageUrlOf(mixed $image, Closure $imageUrl): ?string
    {
        return is_array($image) && is_string($image['id'] ?? null) ? $imageUrl($image['id']) : null;
    }

    /**
     * Convocatorias publicadas del evento, calculadas al mostrar (CA-022-24). Un borrador no tiene estado
     * público y no aparece; el filtro de organización y el de evento acotan la consulta.
     *
     * @return list<array<string, mixed>>
     */
    private function calls(Event $event, Organization $organization, string $locale): array
    {
        $route = ['organization' => $organization->slug, 'event' => $event->slug];
        $calls = [];

        $query = CallForSubmissions::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', CallStatus::Draft)
            ->with('submissionType')
            ->orderBy('opens_at')
            ->orderBy('id');

        foreach ($query->get() as $call) {
            $state = ResolveCallState::for($call);

            if ($state === null) {
                continue;
            }

            $data = PublicCallData::from($call, $event, $state, $locale);

            $calls[] = [
                ...Arr::only($data, ['title', 'submissionType', 'state', 'opensAt', 'closesAt', 'timezone', 'timeFormat']),
                // Solo con la convocatoria abierta hay enlace; si no, el botón queda bloqueado (DA-17). El
                // botón lleva a la PÁGINA de la convocatoria, no directo al formulario (decisión del organizador).
                'registerUrl' => $state === CallState::Open
                    ? route('calls.show', $route + ['call' => $call->slug], false)."?lang={$locale}"
                    : null,
            ];
        }

        return $calls;
    }

    /** @return list<array{name: string, description: ?string}> */
    private function tracks(Event $event, string $locale): array
    {
        return $event->tracks()->active()->orderBy('position')->get()
            ->map(fn (Track $track): array => [
                'name' => $this->translated($track, 'name', $locale, $event->default_locale),
                'description' => $this->nullable($this->translated($track, 'description', $locale, $event->default_locale)),
            ])
            ->values()
            ->all();
    }

    /** Texto traducible de un eje en el idioma pedido; si falta, en el idioma base del evento. */
    private function translated(Track $model, string $attribute, string $locale, string $fallback): string
    {
        $text = (string) $model->getTranslation($attribute, $locale, false);

        return $text !== '' ? $text : (string) $model->getTranslation($attribute, $fallback, false);
    }

    /** Texto por idioma en el idioma pedido; si falta, en el idioma base del evento. */
    private function text(Event $event, mixed $localized, string $locale): string
    {
        if (! is_array($localized)) {
            return '';
        }

        $text = $localized[$locale] ?? null;

        if (! is_string($text) || $text === '') {
            $text = $localized[$event->default_locale] ?? '';
        }

        return is_string($text) ? $text : '';
    }

    private function nullable(string $text): ?string
    {
        return $text === '' ? null : $text;
    }
}
