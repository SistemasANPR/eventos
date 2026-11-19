<?php

declare(strict_types=1);

use App\Enums\LandingBlockType;
use App\Models\Event;
use App\Support\Landings\LandingBlockRules;
use App\Support\Landings\LandingBlocks;
use App\Support\Landings\LandingTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function landingEvent(): Event
{
    return Event::factory()->create(['locales' => ['es', 'en'], 'default_locale' => 'es']);
}

/**
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function heroBlock(array $fields = []): array
{
    return ['type' => 'hero', 'title' => ['es' => 'Congreso'], ...$fields];
}

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return array<string, string> errores por campo, o vacío si pasó
 */
function landingErrors(Event $event, array $blocks): array
{
    try {
        LandingBlocks::validate($event, $blocks);
    } catch (ValidationException $exception) {
        return array_map(fn (array $messages): string => $messages[0], $exception->errors());
    }

    return [];
}

it('CA-022-9: the block types are exactly the nine of the whitelist', function (): void {
    expect(LandingBlockType::values())->toBe([
        'hero', 'rich_text', 'event_info', 'calls', 'tracks', 'faq', 'image_gallery', 'spacer', 'columns',
    ]);
});

it('CA-022-9: a block type outside the whitelist is rejected with a field error', function (): void {
    $errors = landingErrors(landingEvent(), [heroBlock(), ['type' => 'iframe']]);

    expect($errors)->toHaveKey('blocks.1.type');
});

it('CA-022-9: fields the type does not admit are rejected, at any depth', function (): void {
    $event = landingEvent();

    expect(landingErrors($event, [heroBlock(['onclick' => 'x'])]))->toHaveKey('blocks.0.onclick')
        ->and(landingErrors($event, [['type' => 'spacer', 'size' => 'md', 'html' => '<b>']]))->toHaveKey('blocks.0.html')
        ->and(landingErrors($event, [['type' => 'event_info', 'name' => 'copia']]))->toHaveKey('blocks.0.name')
        ->and(landingErrors($event, [['type' => 'faq', 'items' => [['question' => ['es' => 'P'], 'answer' => ['es' => 'R'], 'extra' => 1]]]]))
        ->toHaveKey('blocks.0.items.0.extra');
});

it('CA-022-9: one invalid block rejects the whole list', function (): void {
    $errors = landingErrors(landingEvent(), [heroBlock(), ['type' => 'hero']]);

    expect($errors)->toHaveKey('blocks.1.title.es');
});

it('CA-022-9: text keyed by a language the event does not offer is rejected', function (): void {
    expect(landingErrors(landingEvent(), [heroBlock(['subtitle' => ['fr' => 'Bonjour']])]))->toHaveKey('blocks.0.subtitle.fr');
});

it('CA-022-9: the editor draft is accepted as it comes: empty languages, client keys and image urls are ignored', function (): void {
    $event = landingEvent();
    $image = (string) Str::uuid();

    $blocks = LandingBlocks::validate($event, [
        [...heroBlock(['subtitle' => ['es' => '', 'en' => ''], 'image' => ['id' => $image, 'url' => '/admin/x'], 'buttonUrl' => '', 'buttonLabel' => ['es' => '', 'en' => '']]), 'id' => 'new-1'],
        ['type' => 'faq', 'items' => [['key' => 'k1', 'question' => ['es' => 'P', 'en' => ''], 'answer' => ['es' => '<p>R</p>', 'en' => '']]]],
        ['type' => 'columns', 'columns' => [['kind' => 'text', 'html' => ['es' => '<p>A</p>']], ['kind' => 'image', 'image' => ['id' => $image], 'alt' => ['es' => 'Foto']]]],
    ]);

    expect($blocks[0]['image'])->toBe(['id' => $image])
        ->and($blocks[0]['subtitle'])->toBeNull()
        ->and($blocks[0]['buttonUrl'])->toBeNull()
        ->and($blocks[1]['items'][0])->toBe(['question' => ['es' => 'P'], 'answer' => ['es' => '<p>R</p>']])
        ->and($blocks[2]['columns'][1])->toBe(['kind' => 'image', 'image' => ['id' => $image], 'alt' => ['es' => 'Foto']]);
});

it('CA-022-9: a columns block needs exactly two columns, each with what its kind needs', function (): void {
    $event = landingEvent();

    expect(landingErrors($event, [['type' => 'columns', 'columns' => [['kind' => 'text', 'html' => ['es' => 'A']]]]]))->toHaveKey('blocks.0.columns')
        ->and(landingErrors($event, [['type' => 'columns', 'columns' => [['kind' => 'text'], ['kind' => 'image']]]]))
        ->toHaveKeys(['blocks.0.columns.0.html', 'blocks.0.columns.1.image']);
});

it('CA-022-10: more than 30 blocks are rejected naming the limit', function (): void {
    $errors = landingErrors(landingEvent(), array_fill(0, 31, ['type' => 'spacer']));

    expect($errors['blocks'])->toContain((string) LandingBlockRules::MAX_BLOCKS);
});

it('CA-022-10: a gallery of more than 12 images and a faq of more than 20 questions are rejected', function (): void {
    $event = landingEvent();
    $images = array_map(fn (): array => ['image' => ['id' => (string) Str::uuid()], 'alt' => ['es' => 'Foto']], range(1, 13));
    $questions = array_map(fn (): array => ['question' => ['es' => 'P'], 'answer' => ['es' => 'R']], range(1, 21));

    expect(landingErrors($event, [['type' => 'image_gallery', 'images' => $images]]))->toHaveKey('blocks.0.images')
        ->and(landingErrors($event, [['type' => 'faq', 'items' => $questions]]))->toHaveKey('blocks.0.items');
});

it('CA-022-10: more than 20 distinct images in the landing are rejected', function (): void {
    $gallery = fn (): array => ['type' => 'image_gallery', 'images' => array_map(
        fn (): array => ['image' => ['id' => (string) Str::uuid()], 'alt' => ['es' => 'Foto']],
        range(1, 11),
    )];

    expect(landingErrors(landingEvent(), [$gallery(), $gallery()]))->toHaveKey('blocks');
});

it('CA-022-10: a draft above 128 KB is rejected', function (): void {
    $block = fn (): array => ['type' => 'rich_text', 'html' => ['es' => '<p>'.str_repeat('a', 4_900).'</p>', 'en' => '<p>'.str_repeat('b', 4_900).'</p>']];

    expect(landingErrors(landingEvent(), array_map($block, range(1, 14))))->toHaveKey('blocks');
});

it('CA-022-10: a rich text above 5000 characters is rejected', function (): void {
    $errors = landingErrors(landingEvent(), [['type' => 'rich_text', 'html' => ['es' => str_repeat('a', 5_001)]]]);

    expect($errors)->toHaveKey('blocks.0.html.es');
});

it('CA-022-11: rich text is sanitized with the whitelist when saved', function (): void {
    $dirty = '<p onclick="x()">Hola</p><script>alert(1)</script><iframe src="https://a.b"></iframe><svg onload="x"></svg>'
        .'<a href="javascript:alert(1)">mal</a><img src="data:image/png;base64,AAA"><b style="color:red">negrita</b>';

    $blocks = LandingBlocks::validate(landingEvent(), [['type' => 'rich_text', 'html' => ['es' => $dirty]]]);

    expect($blocks[0]['html']['es'])->toContain('Hola', 'negrita');
    expect($blocks[0]['html']['es'])->not->toContain('<script', '<iframe', '<svg', 'onclick', 'onload', 'javascript:', 'data:', '<img', 'style=');
});

it('CA-022-12: a button with javascript, data or http destination is rejected; https is accepted', function (string $url, bool $accepted): void {
    $errors = landingErrors(landingEvent(), [heroBlock(['buttonLabel' => ['es' => 'Ir'], 'buttonUrl' => $url])]);

    expect($errors === [])->toBe($accepted);
})->with([
    'javascript' => ['javascript:alert(1)', false],
    'data' => ['data:text/html;base64,PHNjcmlwdD4=', false],
    'http' => ['http://ejemplo.com', false],
    'relative' => ['/privado', false],
    'credentials' => ['https://user:pass@ejemplo.com', false],
    'spaces' => ['https://ejemplo.com/a b', false],
    'https' => ['https://ejemplo.com/ruta?x=1', true],
]);

it('CA-022-12: a button needs its label when it has a destination', function (): void {
    expect(landingErrors(landingEvent(), [heroBlock(['buttonUrl' => 'https://ejemplo.com'])]))->toHaveKey('blocks.0.buttonLabel.es');
});

it('CA-022-13: an image without alternative text in the base language is rejected', function (): void {
    $event = landingEvent();
    $image = ['id' => (string) Str::uuid()];

    $gallery = fn (array $alt): array => ['type' => 'image_gallery', 'images' => [['image' => $image, 'alt' => $alt]]];
    $columns = fn (array $alt): array => ['type' => 'columns', 'columns' => [['kind' => 'image', 'image' => $image, 'alt' => $alt], ['kind' => 'text', 'html' => ['es' => 'Texto']]]];

    expect(landingErrors($event, [$gallery([])]))->toHaveKey('blocks.0.images.0.alt.es')
        ->and(landingErrors($event, [$gallery(['en' => 'Photo'])]))->toHaveKey('blocks.0.images.0.alt.es')
        ->and(landingErrors($event, [$columns([])]))->toHaveKey('blocks.0.columns.0.alt.es')
        ->and(landingErrors($event, [$gallery(['es' => 'Foto'])]))->toBe([])
        ->and(landingErrors($event, [$columns(['es' => 'Foto'])]))->toBe([]);
});

it('CA-022-14: a block id sent by the client is ignored; one already in the draft keeps its identity', function (): void {
    $event = landingEvent();
    $known = (string) Str::uuid();
    $forged = (string) Str::uuid();

    $blocks = LandingBlocks::validate($event, [
        [...heroBlock(), 'id' => $known],
        [...heroBlock(), 'id' => $forged],
        [...heroBlock(), 'id' => $known],
    ], [$known]);

    expect($blocks[0]['id'])->toBe($known)
        ->and($blocks[1]['id'])->not->toBe($forged)->and(Str::isUuid($blocks[1]['id']))->toBeTrue()
        ->and($blocks[2]['id'])->not->toBe($known)
        ->and(array_unique(array_column($blocks, 'id')))->toHaveCount(3);
});

it('CA-022-14: what the editor receives is accepted back unchanged', function (): void {
    $event = landingEvent();
    $stored = LandingTemplate::blocks($event);
    $stored[] = LandingBlocks::validate($event, [['type' => 'faq', 'items' => [['question' => ['es' => 'P'], 'answer' => ['es' => '<p>R</p>']]]]])[0];

    $forEditor = LandingBlocks::forEditor($stored, $event->offeredLocales(), fn (string $id): string => "/img/{$id}");

    expect($forEditor[0]['title'])->toHaveKeys(['es', 'en'])
        ->and($forEditor[4]['items'][0]['question'])->toBe(['es' => 'P', 'en' => ''])
        ->and(LandingBlocks::validate($event, $forEditor, array_column($stored, 'id')))->toEqual($stored);
});

it('CA-022-1: the default template is hero, event info, calls and tracks, valid for the editor', function (): void {
    $event = landingEvent();
    $template = LandingTemplate::blocks($event);

    expect(array_column($template, 'type'))->toBe(['hero', 'event_info', 'calls', 'tracks'])
        ->and($template[0]['title']['es'])->toBe($event->getTranslation('name', 'es'))
        ->and($template[0]['title']['en'])->toBe($event->getTranslation('name', 'en'))
        ->and(LandingBlocks::validate($event, $template, array_column($template, 'id')))->toEqual($template);
});
