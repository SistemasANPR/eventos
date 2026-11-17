<?php

declare(strict_types=1);

use App\Support\Html\RichTextSanitizer;

/*
 * Plan 021, CA-021-2 y DA-16: la biografía enriquecida se sanea con una lista blanca y no deja pasar
 * script ni atributos de evento. Cargas tomadas de las listas públicas de XSS (OWASP, PortSwigger).
 */

it('CA-021-2: keeps the allowed formatting', function (): void {
    // El sanitizador serializa el salto de línea como `<br />`.
    $html = '<h2>Título</h2><h3>Sub</h3><p>Hola <strong>mundo</strong> <b>b</b> <em>e</em> <i>i</i><br />fin</p>'
        .'<ul><li>uno</li></ul><ol><li>dos</li></ol><blockquote>cita</blockquote>';

    expect(RichTextSanitizer::clean($html))->toBe($html);
});

it('CA-021-2: removes script, style, iframe and their content', function (string $payload, string $forbidden): void {
    $clean = RichTextSanitizer::clean("<p>antes</p>{$payload}<p>después</p>");

    expect($clean)->toContain('antes', 'después')
        ->and($clean)->not->toContain($forbidden, '<script', '<iframe', '<style');
})->with([
    'script' => ['<script>alert(1)</script>', 'alert'],
    'script uppercase' => ['<SCRIPT SRC=//evil.test/x.js></SCRIPT>', 'evil.test'],
    'style' => ['<style>body{background:url(javascript:alert(1))}</style>', 'background'],
    'iframe' => ['<iframe src="https://evil.test"></iframe>', 'evil.test'],
    'object' => ['<object data="https://evil.test/x.swf"></object>', 'evil.test'],
    'embed' => ['<embed src="https://evil.test/x">', 'evil.test'],
    'svg onload' => ['<svg onload=alert(1)><circle/></svg>', 'onload'],
    'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'javascript'],
    'form' => ['<form action="https://evil.test"><input name=x></form>', 'evil.test'],
]);

it('CA-021-2: removes event handler attributes and style attributes', function (string $payload): void {
    $clean = RichTextSanitizer::clean($payload);

    expect($clean)->not->toMatch('/\son\w+\s*=/i')
        ->and($clean)->not->toContain('style=', 'alert');
})->with([
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'p onclick' => ['<p onclick="alert(1)">texto</p>'],
    'strong onmouseover' => ['<strong onmouseover="alert(1)">texto</strong>'],
    'broken tag' => ['<p/onclick=alert(1)>texto</p>'],
    'style attribute' => ['<p style="background:url(javascript:alert(1))">texto</p>'],
    'img src js' => ['<IMG SRC="javascript:alert(1)">'],
    'body onload' => ['<body onload=alert(1)>texto'],
    'nested mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
]);

it('CA-021-2: only https links survive, with rel and no target', function (string $href): void {
    $clean = RichTextSanitizer::clean("<p><a href=\"{$href}\">enlace</a></p>");

    expect($clean)->toContain('enlace')
        ->and($clean)->not->toContain('href');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'javascript mixed case' => ['JaVaScRiPt:alert(1)'],
    'javascript with tab' => ["java\tscript:alert(1)"],
    'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
    'vbscript' => ['vbscript:msgbox(1)'],
    'http' => ['http://example.com'],
    'relative' => ['/admin/users'],
    'protocol relative' => ['//evil.test/x'],
    'mailto' => ['mailto:a@example.com'],
]);

it('CA-021-2: an https link keeps its address and gains rel noopener noreferrer nofollow', function (): void {
    $clean = RichTextSanitizer::clean('<p><a href="https://example.com/x" target="_blank" onclick="x()" rel="opener" class="y">sitio</a></p>');

    expect($clean)->toBe('<p><a href="https://example.com/x" rel="noopener noreferrer nofollow">sitio</a></p>');
});

it('CA-021-2: drops comments and keeps the text of elements outside the list', function (): void {
    $clean = RichTextSanitizer::clean('<div><!-- comentario --><span>texto</span> <u>subrayado</u> <h1>grande</h1></div><img src="https://example.com/a.png" alt="x">');

    expect($clean)->toContain('texto', 'subrayado', 'grande')
        ->and($clean)->not->toContain('<!--', '<div', '<span', '<img', '<h1');
});

it('CA-021-2: returns an empty string for blank input and for markup without text', function (): void {
    expect(RichTextSanitizer::clean(''))->toBe('')
        ->and(RichTextSanitizer::clean("  \n "))->toBe('')
        ->and(RichTextSanitizer::clean('<script>alert(1)</script>'))->toBe('');
});

it('CA-021-2: cleaning is idempotent', function (): void {
    $once = RichTextSanitizer::clean('<p onclick="x()">Hola <a href="https://example.com">a</a><script>1</script></p>');

    expect(RichTextSanitizer::clean($once))->toBe($once);
});
