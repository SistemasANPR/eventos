<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Support\Panel\InitialsAvatarProvider;
use Filament\Facades\Filament;

function avatarSvg(string $dataUri): string
{
    expect($dataUri)->toStartWith('data:image/svg+xml;charset=utf-8,');

    return rawurldecode(substr($dataUri, strlen('data:image/svg+xml;charset=utf-8,')));
}

it('draws the avatar of a person as inline SVG without contacting third parties', function (): void {
    $user = User::factory()->make(['name' => 'Admin Demo']);

    $svg = avatarSvg((new InitialsAvatarProvider)->get($user));

    expect($svg)->toContain('>AD</text>')
        ->and($svg)->toContain('fill="#243342"')
        // El único `http://` permitido es el espacio de nombres de SVG: no es una petición de red.
        ->and($svg)->not->toContain('https://')
        ->and(substr_count($svg, 'http://'))->toBe(1);
});

it('draws the avatar of an organization in lime with blue letters and skips short words', function (): void {
    $organization = Organization::factory()->make(['name' => 'Asociación Nacional de Parques y Recreación']);

    $svg = avatarSvg((new InitialsAvatarProvider)->get($organization));

    expect($svg)->toContain('>AN</text>')
        ->and($svg)->toContain('fill="#d2dd0a"')
        ->and($svg)->toContain('fill="#012787"');
});

it('escapes the characters of the name that are special in XML', function (): void {
    $user = User::factory()->make(['name' => '<Zeta> & Co']);

    $svg = avatarSvg((new InitialsAvatarProvider)->get($user));

    expect(simplexml_load_string($svg))->not->toBeFalse()
        ->and($svg)->not->toContain('<Z');
});

it('is the default avatar provider of every panel', function (string $panel): void {
    expect(Filament::getPanel($panel)->getDefaultAvatarProvider())->toBe(InitialsAvatarProvider::class);
})->with(['admin', 'platform', 'reviewer']);
