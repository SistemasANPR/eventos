<?php

declare(strict_types=1);

use App\Enums\TimeFormat;
use App\Support\Events\EventTime;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    app()->setLocale('es');
});

function eventTime(TimeFormat $format, string $timezone = 'America/Mexico_City'): EventTime
{
    return new EventTime($format, $timezone);
}

/** Instante UTC que equivale a la hora local dada en la zona del evento. */
function utcOf(string $local, string $timezone = 'America/Mexico_City'): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('Y-m-d H:i', $local, $timezone)->utc();
}

it('CA-022-4: formats 14:30 as 12 hours or 24 hours in Spanish', function (): void {
    app()->setLocale('es');

    expect(eventTime(TimeFormat::TwelveHour)->time(utcOf('2027-03-10 14:30')))->toBe('2:30 p. m.')
        ->and(eventTime(TimeFormat::TwentyFourHour)->time(utcOf('2027-03-10 14:30')))->toBe('14:30 h');
});

it('CA-022-4: formats 14:30 in English without the Spanish suffix', function (): void {
    app()->setLocale('en');

    expect(eventTime(TimeFormat::TwelveHour)->time(utcOf('2027-03-10 14:30')))->toBe('2:30 pm')
        ->and(eventTime(TimeFormat::TwentyFourHour)->time(utcOf('2027-03-10 14:30')))->toBe('14:30');
});

it('CA-022-5: midnight and noon are unambiguous in 12 hours', function (): void {
    app()->setLocale('es');
    $twelve = eventTime(TimeFormat::TwelveHour);

    expect($twelve->time(utcOf('2027-03-10 00:00')))->toBe('12:00 a. m.')
        ->and($twelve->time(utcOf('2027-03-10 12:00')))->toBe('12:00 p. m.')
        ->and($twelve->time(utcOf('2027-03-10 09:05')))->toBe('9:05 a. m.');
});

it('CA-022-6: every end of a range carries its own marker', function (): void {
    app()->setLocale('es');
    $from = utcOf('2027-03-10 09:00');
    $to = utcOf('2027-03-10 14:30');

    expect(eventTime(TimeFormat::TwelveHour)->range($from, $to))->toBe('9:00 a. m. – 2:30 p. m.')
        ->and(eventTime(TimeFormat::TwentyFourHour)->range($from, $to))->toBe('09:00 h – 14:30 h');
});

it('CA-022-7: shows the event time zone and never changes the stored instant', function (): void {
    app()->setLocale('es');
    $instant = CarbonImmutable::parse('2027-03-10 20:30:00', 'UTC');
    $before = $instant->toIso8601String();

    expect(eventTime(TimeFormat::TwentyFourHour, 'America/Mexico_City')->time($instant))->toBe('14:30 h')
        ->and(eventTime(TimeFormat::TwentyFourHour, 'Europe/Madrid')->time($instant))->toBe('21:30 h')
        ->and(eventTime(TimeFormat::TwelveHour, 'Europe/Madrid')->time($instant))->toBe('9:30 p. m.')
        ->and($instant->toIso8601String())->toBe($before);
});

it('formats a stored local time as is, without converting the zone', function (): void {
    app()->setLocale('es');

    expect(eventTime(TimeFormat::TwelveHour, 'Asia/Tokyo')->localTime('14:30:00'))->toBe('2:30 p. m.')
        ->and(eventTime(TimeFormat::TwentyFourHour, 'Asia/Tokyo')->localTime('09:05'))->toBe('09:05 h');
});

it('formats a date with its time in the event zone', function (): void {
    app()->setLocale('es');
    $instant = utcOf('2027-01-15 09:00');

    expect(eventTime(TimeFormat::TwentyFourHour)->dateTime($instant, 'd/m/Y', ' '))->toBe('15/01/2027 09:00 h')
        ->and(eventTime(TimeFormat::TwelveHour)->dateTime($instant, 'd/m/Y', ' '))->toBe('15/01/2027 9:00 a. m.');
});

it('rejects a stored time that is not a time', function (): void {
    eventTime(TimeFormat::TwelveHour)->localTime('no-es-hora');
})->throws(InvalidArgumentException::class);
