<?php

declare(strict_types=1);

use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function noticeRow(Event $event, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(),
        'organization_id' => $event->organization_id,
        'event_id' => $event->id,
        'version' => 1,
        'body' => json_encode(['es' => '<p>Aviso</p>']),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

/** @param  array<string, mixed>  $row */
function insertNotice(array $row): bool
{
    return DB::transaction(fn (): bool => DB::table('privacy_notices')->insert($row));
}

function publishedNoticeRow(Event $event, int $version = 1): string
{
    $user = User::factory()->create();
    $row = noticeRow($event, ['version' => $version, 'published_at' => now(), 'published_by' => $user->id]);
    insertNotice($row);

    return $row['id'];
}

it('CA-003-14: the database refuses to edit a published notice', function (): void {
    $event = Event::factory()->create();
    $id = publishedNoticeRow($event);

    expect(fn () => DB::transaction(fn () => DB::table('privacy_notices')->where('id', $id)->update(['body' => json_encode(['es' => '<p>Otro</p>'])])))
        ->toThrow(QueryException::class, 'publicado');
});

it('CA-003-14: the database refuses to delete a published notice', function (): void {
    $event = Event::factory()->create();
    $id = publishedNoticeRow($event);

    expect(fn () => DB::transaction(fn () => DB::table('privacy_notices')->where('id', $id)->delete()))
        ->toThrow(QueryException::class, 'publicado');

    expect(DB::table('privacy_notices')->where('id', $id)->exists())->toBeTrue();
});

it('CA-003-11: a draft can be edited and deleted', function (): void {
    $event = Event::factory()->create();
    $row = noticeRow($event);
    insertNotice($row);

    DB::table('privacy_notices')->where('id', $row['id'])->update(['body' => json_encode(['es' => '<p>Nuevo</p>'])]);
    expect(DB::table('privacy_notices')->where('id', $row['id'])->value('body'))->toContain('Nuevo');

    DB::table('privacy_notices')->where('id', $row['id'])->delete();
    expect(DB::table('privacy_notices')->where('id', $row['id'])->exists())->toBeFalse();
});

it('CA-003-13: publishing a draft is the one allowed change and needs who published', function (): void {
    $event = Event::factory()->create();
    $row = noticeRow($event);
    insertNotice($row);
    $user = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('privacy_notices')->where('id', $row['id'])->update(['published_at' => now()])))
        ->toThrow(QueryException::class);

    DB::table('privacy_notices')->where('id', $row['id'])->update(['published_at' => now(), 'published_by' => $user->id]);

    expect(DB::table('privacy_notices')->where('id', $row['id'])->value('published_by'))->toBe($user->id);
});

it('CA-003-14: a version number is unique per event', function (): void {
    $event = Event::factory()->create();
    publishedNoticeRow($event, 1);

    expect(fn () => insertNotice(noticeRow($event, ['version' => 1])))->toThrow(QueryException::class);
});

it('CA-003-14: an event has at most one open draft', function (): void {
    $event = Event::factory()->create();
    insertNotice(noticeRow($event, ['version' => 1]));

    expect(fn () => insertNotice(noticeRow($event, ['version' => 2])))->toThrow(QueryException::class);
});

it('CA-003-14: the version must be at least 1', function (): void {
    $event = Event::factory()->create();

    expect(fn () => insertNotice(noticeRow($event, ['version' => 0])))->toThrow(QueryException::class);
});

it('CA-003-18: a notice cannot point at an event of another organization', function (): void {
    $event = Event::factory()->create();
    $other = Organization::factory()->create();

    expect(fn () => insertNotice(noticeRow($event, ['organization_id' => $other->id])))->toThrow(QueryException::class);
});

it('CA-003-18: organization_id of a notice cannot be changed', function (): void {
    $event = Event::factory()->create();
    $row = noticeRow($event);
    insertNotice($row);
    $other = Organization::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('privacy_notices')->where('id', $row['id'])->update(['organization_id' => $other->id])))
        ->toThrow(QueryException::class);
});
