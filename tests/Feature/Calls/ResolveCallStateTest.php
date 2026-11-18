<?php

declare(strict_types=1);

use App\Enums\CallState;
use App\Enums\CallStatus;
use App\Models\CallForSubmissions;
use App\Support\Calls\ResolveCallState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Convocatoria publicada de T1 = 2027-01-10 15:00 UTC a T2 = 2027-02-15 23:00 UTC.
 *
 * @param  array<string, mixed>  $attributes
 */
function publishedCall(array $attributes = []): CallForSubmissions
{
    return CallForSubmissions::factory()->create([
        'status' => CallStatus::Published,
        'opens_at' => '2027-01-10 15:00:00',
        'closes_at' => '2027-02-15 23:00:00',
        ...$attributes,
    ]);
}

it('CA-004-17: the public state follows the dates, with the opening included and the close excluded', function (string $instant, CallState $expected): void {
    $call = publishedCall();

    expect(ResolveCallState::for($call, CarbonImmutable::parse($instant, 'UTC')))->toBe($expected);
})->with([
    'a second before opening' => ['2027-01-10 14:59:59', CallState::Scheduled],
    'at the opening' => ['2027-01-10 15:00:00', CallState::Open],
    'a second before the close' => ['2027-02-15 22:59:59', CallState::Open],
    'at the close' => ['2027-02-15 23:00:00', CallState::Closed],
    'long after the close' => ['2027-06-01 00:00:00', CallState::Closed],
]);

it('CA-004-18: the state changes with the clock and needs no scheduled command', function (): void {
    $call = publishedCall();

    $this->travelTo(CarbonImmutable::parse('2027-02-15 22:59:59', 'UTC'));
    expect(ResolveCallState::for($call))->toBe(CallState::Open);

    $this->travelTo(CarbonImmutable::parse('2027-02-15 23:00:01', 'UTC'));
    expect(ResolveCallState::for($call))->toBe(CallState::Closed);
});

it('CA-004-17: a draft has no public state', function (): void {
    expect(ResolveCallState::for(publishedCall(['status' => CallStatus::Draft])))->toBeNull();
});

it('CA-004-17: a paused call is paused and a manually closed one is closed, whatever the dates', function (): void {
    $now = CarbonImmutable::parse('2027-01-20 12:00:00', 'UTC');

    expect(ResolveCallState::for(publishedCall(['status' => CallStatus::Paused]), $now))->toBe(CallState::Paused)
        ->and(ResolveCallState::for(publishedCall(['status' => CallStatus::Closed]), $now))->toBe(CallState::Closed);
});
