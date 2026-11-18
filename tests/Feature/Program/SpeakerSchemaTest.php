<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Plan 021, F2: el esquema de `speakers` y de su pivote con categorías rechaza lo inválido por sí mismo.
 */

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function speakerRow(Event $event, array $attributes = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'social_links' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ];
}

it('CA-021-1: a speaker is published by default and only the first and last name are required', function (): void {
    $event = Event::factory()->create();
    DB::table('speakers')->insert(speakerRow($event));

    $row = DB::table('speakers')->first();

    expect($row->is_published)->toBeTrue()
        ->and($row->email)->toBeNull()
        ->and($row->hidden_by_reversal_at)->toBeNull()
        ->and($row->social_links)->toBe('[]');
});

it('CA-021-1: the database refuses a blank name', function (string $field): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('speakers')->insert(speakerRow($event, [$field => '  ']))))
        ->toThrow(QueryException::class);
})->with(['first_name', 'last_name']);

it('CA-021-2: social_links must be a JSON array', function (): void {
    $event = Event::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('speakers')->insert(speakerRow($event, ['social_links' => '{"network":"x"}']))))
        ->toThrow(QueryException::class);
});

it('CA-021-1: the database refuses the same email in one event without regard to case', function (): void {
    $event = Event::factory()->create();
    Speaker::factory()->create(['event_id' => $event->id, 'email' => 'Ada@Example.com']);

    expect(fn () => DB::transaction(fn () => Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ada@example.COM'])))
        ->toThrow(QueryException::class);
});

it('CA-021-1: the same email is allowed in another event, and speakers without email never collide', function (): void {
    $first = Event::factory()->create();
    $second = Event::factory()->create(['organization_id' => $first->organization_id]);

    Speaker::factory()->create(['event_id' => $first->id, 'email' => 'ada@example.com']);
    Speaker::factory()->create(['event_id' => $second->id, 'email' => 'ada@example.com']);
    Speaker::factory()->count(2)->create(['event_id' => $first->id, 'email' => null]);

    expect(DB::table('speakers')->count())->toBe(4);
});

it('CA-021-16: a deleted speaker frees its email and does not appear in the lists', function (): void {
    $event = Event::factory()->create();
    $speaker = Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ada@example.com']);
    CurrentOrganization::set(Organization::query()->findOrFail($event->organization_id));

    $speaker->delete();
    Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ada@example.com']);

    expect(Speaker::query()->count())->toBe(1)
        ->and(Speaker::query()->withTrashed()->count())->toBe(2);
});

it('CA-021-17: the composite foreign key refuses an event of another organization', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('speakers')->insert(speakerRow($event, ['organization_id' => $other->id]))))
        ->toThrow(QueryException::class);
});

it('CA-021-17: the optional contact must belong to the same organization', function (): void {
    $event = Event::factory()->create();
    $own = Contact::factory()->create(['organization_id' => $event->organization_id]);
    $foreign = Contact::factory()->create();

    DB::table('speakers')->insert(speakerRow($event, ['contact_id' => $own->id]));

    expect(fn () => DB::transaction(fn () => DB::table('speakers')->insert(speakerRow($event, ['contact_id' => $foreign->id]))))
        ->toThrow(QueryException::class);
});

it('CA-021-6: a speaker admits several categories of its own event and the pivot refuses one of another event', function (): void {
    $event = Event::factory()->create();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $speaker = Speaker::factory()->create(['event_id' => $event->id]);
    $one = SpeakerCategory::factory()->create(['event_id' => $event->id]);
    $two = SpeakerCategory::factory()->create(['event_id' => $event->id]);
    $foreign = SpeakerCategory::factory()->create(['event_id' => $other->id]);
    CurrentOrganization::set(Organization::query()->findOrFail($event->organization_id));

    $pivot = fn (SpeakerCategory $category): array => [
        'speaker_id' => $speaker->id,
        'speaker_category_id' => $category->id,
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
    ];

    DB::table('speaker_speaker_category')->insert([$pivot($one), $pivot($two)]);

    expect($speaker->categories()->count())->toBe(2)
        ->and(fn () => DB::transaction(fn () => DB::table('speaker_speaker_category')->insert($pivot($foreign))))
        ->toThrow(QueryException::class);
});

it('CA-021-6: deleting a category clears its links but keeps the speaker', function (): void {
    $event = Event::factory()->create();
    $speaker = Speaker::factory()->create(['event_id' => $event->id]);
    $category = SpeakerCategory::factory()->create(['event_id' => $event->id]);
    DB::table('speaker_speaker_category')->insert([
        'speaker_id' => $speaker->id,
        'speaker_category_id' => $category->id,
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
    ]);

    DB::table('speaker_categories')->where('id', $category->id)->delete();

    expect(DB::table('speaker_speaker_category')->count())->toBe(0)
        ->and(DB::table('speakers')->count())->toBe(1);
});

it('CA-021-17: a speaker belongs to one event and an organization with no context cannot query speakers', function (): void {
    Speaker::factory()->create();

    expect(fn () => Speaker::query()->count())->toThrow(Exception::class);
});

it('exposes the full name for display', function (): void {
    $speaker = Speaker::factory()->make(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($speaker->full_name)->toBe('Ada Lovelace');
});
