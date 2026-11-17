<?php

declare(strict_types=1);

use App\Support\Privacy\NoticeSanitizer;

/*
 * CA-003-15 (plan 003, DA-9): lista blanca de etiquetas de formato; todo lo demás
 * se elimina en el servidor ANTES de guardar. No se rechaza el texto ni se
 * guarda el original.
 */

it('CA-003-15: keeps the basic formatting tags', function (): void {
    $html = '<p>Uno <strong>dos</strong> <em>tres</em></p><ul><li>a</li><li>b</li></ul><ol><li>c</li></ol><p>línea<br />nueva</p>';

    expect((new NoticeSanitizer)->clean($html))->toBe($html);
});

it('CA-003-15: removes scripts and their content', function (string $dirty): void {
    $clean = (new NoticeSanitizer)->clean($dirty);

    expect($clean)->not->toContain('script', 'alert');
})->with([
    'script tag' => '<p>Hola</p><script>alert(1)</script>',
    'script with attributes' => '<script src="https://evil.test/x.js"></script><p>Hola</p>',
    'nested script' => '<p><script>alert(1)</script>Texto</p>',
    'uppercase script' => '<SCRIPT>alert(1)</SCRIPT><p>Hola</p>',
]);

it('CA-003-15: removes event handlers and dangerous attributes', function (string $dirty): void {
    $clean = (new NoticeSanitizer)->clean($dirty);

    expect($clean)->not->toContain('onerror', 'onclick', 'onload', 'style=', 'alert');
})->with([
    'img onerror' => '<p>x</p><img src="x" onerror="alert(1)">',
    'p onclick' => '<p onclick="alert(1)">texto</p>',
    'svg onload' => '<svg onload="alert(1)"></svg><p>texto</p>',
    'style attribute' => '<p style="background:url(javascript:alert(1))">texto</p>',
    'body onload' => '<body onload="alert(1)"><p>texto</p></body>',
]);

it('CA-003-15: removes elements outside the whitelist but keeps harmless text', function (): void {
    $clean = (new NoticeSanitizer)->clean('<h1>Título</h1><div><span>cuerpo</span></div><iframe src="https://evil.test"></iframe><style>p{}</style><form><input></form>');

    expect($clean)->not->toContain('<h1', '<div', '<span', 'iframe', '<style', '<form', '<input');
    expect($clean)->toContain('Título', 'cuerpo');
});

it('CA-003-15: drops dangerous link schemes and keeps http and https links', function (): void {
    $sanitizer = new NoticeSanitizer;

    expect($sanitizer->clean('<a href="javascript:alert(1)">x</a>'))->not->toContain('javascript', 'href')
        ->and($sanitizer->clean('<a href="JaVaScRiPt:alert(1)">x</a>'))->not->toContain('avascript')
        ->and($sanitizer->clean('<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>'))->not->toContain('data:')
        ->and($sanitizer->clean('<a href="vbscript:msgbox(1)">x</a>'))->not->toContain('vbscript')
        ->and($sanitizer->clean('<a href="/ruta/relativa">x</a>'))->not->toContain('href')
        ->and($sanitizer->clean('<a href="//evil.test/x">x</a>'))->not->toContain('href')
        ->and($sanitizer->clean('<a href="https://ejemplo.mx/privacidad">ver</a>'))->toContain('href="https://ejemplo.mx/privacidad"')
        ->and($sanitizer->clean('<a href="http://ejemplo.mx">ver</a>'))->toContain('href="http://ejemplo.mx"');
});

it('CA-003-15: links carry rel noopener noreferrer nofollow and no target or other attributes', function (): void {
    $clean = (new NoticeSanitizer)->clean('<a href="https://ejemplo.mx" target="_blank" onclick="x()" class="y" rel="opener">ver</a>');

    expect($clean)->toContain('rel="noopener noreferrer nofollow"');
    expect($clean)->not->toContain('target', 'onclick', 'class', 'opener"');
});

it('CA-003-15: leaves harmless text escaped', function (): void {
    expect((new NoticeSanitizer)->clean('<p>a &lt; b &amp; c</p>'))->toBe('<p>a &lt; b &amp; c</p>');
});

it('CA-003-15: keeps the text of tags outside the whitelist, such as b and i, without the tag', function (): void {
    expect((new NoticeSanitizer)->clean('<p><b>negrita</b> y <i>cursiva</i></p>'))->toBe('<p>negrita y cursiva</p>');
});

it('CA-003-15: handles broken markup and empty input', function (): void {
    $sanitizer = new NoticeSanitizer;

    expect($sanitizer->clean(''))->toBe('')
        ->and($sanitizer->clean('   '))->toBe('')
        ->and($sanitizer->clean('<p>sin cerrar <strong>negrita'))->not->toContain('<script')
        ->and($sanitizer->clean('<<script>script>alert(1)<</script>/script>'))->not->toContain('<script');
});

it('CA-003-15: is idempotent', function (): void {
    $sanitizer = new NoticeSanitizer;
    $once = $sanitizer->clean('<p>Hola <a href="https://ejemplo.mx">ver</a></p><script>x</script>');

    expect($sanitizer->clean($once))->toBe($once);
});
