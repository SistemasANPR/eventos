<?php

declare(strict_types=1);

use App\Enums\ProgramSocialNetwork;
use App\Http\Requests\Program\StoreSpeakerRequest;
use App\Http\Requests\Program\UpdateSpeakerRequest;
use App\Models\Event;
use App\Models\Speaker;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CurrentOrganization::forget();
});

/**
 * Valida contra las reglas del Form Request dentro de la organización del evento.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function speakerRequestErrors(Event $event, array $data, ?string $ignoreId = null): array
{
    return CurrentOrganization::run(
        $event->organization,
        fn (): array => Validator::make($data, $ignoreId === null ? StoreSpeakerRequest::rulesFor($event) : UpdateSpeakerRequest::rulesForUpdate($event, $ignoreId))->errors()->toArray(),
    );
}

it('CA-021-1: only first and last name are required', function (): void {
    $event = Event::factory()->create();

    expect(speakerRequestErrors($event, ['first_name' => 'Ada', 'last_name' => 'Lovelace']))->toBe([])
        ->and(array_keys(speakerRequestErrors($event, [])))->toBe(['first_name', 'last_name']);
});

it('CA-021-1: the email must be valid, at most 254 characters and unique in the event without regard to case', function (): void {
    $event = Event::factory()->create();
    Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ada@example.com']);
    $base = ['first_name' => 'X', 'last_name' => 'Y'];

    expect(speakerRequestErrors($event, [...$base, 'email' => 'no-es-correo']))->toHaveKey('email')
        ->and(speakerRequestErrors($event, [...$base, 'email' => str_repeat('a', 250).'@b.co']))->toHaveKey('email')
        ->and(speakerRequestErrors($event, [...$base, 'email' => 'ADA@Example.com']))->toHaveKey('email')
        ->and(speakerRequestErrors($event, [...$base, 'email' => 'otra@example.com']))->toBe([]);
});

it('CA-021-1: the unique email rule ignores the speaker being edited and another event', function (): void {
    $event = Event::factory()->create();
    $other = Event::factory()->create(['organization_id' => $event->organization_id]);
    $speaker = Speaker::factory()->create(['event_id' => $event->id, 'email' => 'ada@example.com']);
    $base = ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'ADA@example.com'];

    expect(speakerRequestErrors($event, $base, $speaker->id))->toBe([])
        ->and(speakerRequestErrors($other, $base))->toBe([]);
});

it('CA-021-2: the country is an ISO code and the text fields have their limits', function (): void {
    $event = Event::factory()->create();
    $base = ['first_name' => 'X', 'last_name' => 'Y'];

    expect(speakerRequestErrors($event, [...$base, 'country' => 'Mexico']))->toHaveKey('country')
        ->and(speakerRequestErrors($event, [...$base, 'country' => 'ZZ']))->toHaveKey('country')
        ->and(speakerRequestErrors($event, [...$base, 'country' => 'MX']))->toBe([])
        ->and(speakerRequestErrors($event, [...$base, 'company' => str_repeat('a', 161)]))->toHaveKey('company')
        ->and(speakerRequestErrors($event, [...$base, 'job_title' => str_repeat('a', 161)]))->toHaveKey('job_title')
        ->and(speakerRequestErrors($event, [...$base, 'tagline' => str_repeat('a', 161)]))->toHaveKey('tagline')
        ->and(speakerRequestErrors($event, [...$base, 'bio' => str_repeat('a', 20001)]))->toHaveKey('bio');
});

it('CA-021-2: every network accepts its domain and refuses the others', function (): void {
    foreach (ProgramSocialNetwork::cases() as $network) {
        foreach ($network->domains() as $domain) {
            expect($network->acceptsHost($domain))->toBeTrue()
                ->and($network->acceptsHost("www.{$domain}"))->toBeTrue()
                ->and($network->acceptsHost("{$domain}.evil.test"))->toBeFalse()
                ->and($network->acceptsHost("evil{$domain}"))->toBeFalse();
        }
    }

    expect(ProgramSocialNetwork::Website->acceptsHost('ada.dev'))->toBeTrue()
        ->and(ProgramSocialNetwork::Website->acceptsHost('localhost'))->toBeFalse()
        ->and(ProgramSocialNetwork::Website->acceptsHost('10.0.0.1'))->toBeFalse()
        ->and(ProgramSocialNetwork::Website->acceptsHost('[::1]'))->toBeFalse()
        ->and(ProgramSocialNetwork::cases())->toHaveCount(7);
});

it('CA-021-2: at most seven social links, one per network', function (): void {
    $event = Event::factory()->create();
    $links = fn (array $networks): array => array_map(fn (string $n): array => ['network' => $n, 'url' => "https://{$n}.com/a"], $networks);
    $base = ['first_name' => 'X', 'last_name' => 'Y'];

    expect(speakerRequestErrors($event, [...$base, 'social_links' => $links(['linkedin', 'instagram'])]))->toBe([])
        ->and(speakerRequestErrors($event, [...$base, 'social_links' => $links(['instagram', 'instagram'])]))->toHaveKey('social_links')
        ->and(speakerRequestErrors($event, [...$base, 'social_links' => array_merge($links(['linkedin', 'instagram', 'facebook', 'youtube', 'tiktok']), [['network' => 'x', 'url' => 'https://x.com/a'], ['network' => 'website', 'url' => 'https://a.dev'], ['network' => 'x', 'url' => 'https://x.com/b']])]))->toHaveKey('social_links');
});

it('CA-021-1: the categories must be unique and belong to the event catalog', function (): void {
    $event = Event::factory()->create();
    $base = ['first_name' => 'X', 'last_name' => 'Y'];

    expect(speakerRequestErrors($event, [...$base, 'category_ids' => ['abc']]))->toHaveKey('category_ids.0')
        ->and(speakerRequestErrors($event, [...$base, 'category_ids' => []]))->toBe([]);
});
