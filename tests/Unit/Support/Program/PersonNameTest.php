<?php

declare(strict_types=1);

use App\Support\Program\PersonName;

it('CA-021-13: splits an earlier full name into first word and the rest', function (string $full, string $first, string $last): void {
    expect(PersonName::split($full))->toBe(['first' => $first, 'last' => $last]);
})->with([
    'two words' => ['Ana Pérez', 'Ana', 'Pérez'],
    'compound surname' => ['Ana Pérez Gómez', 'Ana', 'Pérez Gómez'],
    'single word' => ['Madonna', 'Madonna', ''],
    'empty' => ['', '', ''],
    'only spaces' => ['   ', '', ''],
    'surrounding spaces' => ['  Ana   Pérez  ', 'Ana', 'Pérez'],
    'inner multiple spaces kept in the rest' => ['Ana  de la   Cruz', 'Ana', 'de la   Cruz'],
    'tab and newline as separators' => ["Ana\tPérez\n", 'Ana', 'Pérez'],
    'non-breaking space' => ["Ana\u{00A0}Pérez", 'Ana', 'Pérez'],
    'multibyte' => ['Ñandú Ávila Ørsted', 'Ñandú', 'Ávila Ørsted'],
    'cjk' => ['太郎 山田', '太郎', '山田'],
]);

it('CA-021-13: does not fail on text that is not valid UTF-8', function (): void {
    expect(PersonName::split("Ana\xC3 Pérez"))->toBe(['first' => "Ana\xC3 Pérez", 'last' => '']);
});
