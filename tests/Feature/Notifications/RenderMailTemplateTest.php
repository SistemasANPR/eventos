<?php

declare(strict_types=1);

use App\Actions\Notifications\RenderMailTemplate;
use App\Exceptions\Domain\MailTemplateContextInvalid;
use App\Support\Notifications\NotificationCatalog;
use Illuminate\Validation\ValidationException;

/*
 * Renderizado seguro de plantillas (plan 008, H-008-2, DA-2 y DA-3): Markdown sin HTML
 * primero, con las variables como marcadores opacos; los valores, ya escapados, después.
 */

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function receivedVariables(array $overrides = []): array
{
    return [...[
        'author_name' => 'Ana Pérez',
        'submission_title' => 'Parques vivos',
        'submission_reference' => 'PR-2027-0042',
        'event_name' => 'Congreso Parques 2027',
    ], ...$overrides];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function organizerVariables(array $overrides = []): array
{
    return [...[
        'author_name' => 'Ana Pérez',
        'submission_title' => 'Parques vivos',
        'event_name' => 'Congreso Parques 2027',
        'panel_url' => 'https://panel.example.test/proposals/1',
    ], ...$overrides];
}

function renderer(): RenderMailTemplate
{
    return app(RenderMailTemplate::class);
}

it('CA-008-4: renders subject and body with the values and no unresolved braces', function () {
    $mail = renderer()->handle('submission.received', 'es', receivedVariables());

    expect($mail->subject)->toContain('Congreso Parques 2027');
    expect($mail->html)->toContain('Parques vivos')->toContain('PR-2027-0042');
    expect($mail->text)->toContain('Ana Pérez');

    foreach ([$mail->subject, $mail->html, $mail->text] as $output) {
        expect($output)->not->toContain('{{')->and($output)->not->toContain('}}');
    }
});

it('CA-008-4: renders every catalog key in both locales', function () {
    foreach (app(NotificationCatalog::class)->all() as $key => $descriptor) {
        $values = [];
        foreach ($descriptor->variables as $variable) {
            $values[$variable] = str_ends_with($variable, '_url') ? 'https://example.test/x' : "valor-{$variable}";
        }

        foreach (['es', 'en'] as $locale) {
            $mail = renderer()->handle($key, $locale, $values);

            expect($mail->subject)->not->toContain('{{');
            expect($mail->html)->not->toContain('{{');
        }
    }
});

it('CA-008-5: rejects a variable outside the whitelist and never evaluates it', function () {
    renderer()->handle('submission.received', 'es', receivedVariables(['app_key' => 'SECRETO']), body: 'Clave: {{ app_key }}');
})->throws(ValidationException::class);

it('CA-008-5: rejects a non-whitelisted variable when a template is validated to be saved', function () {
    renderer()->validate('submission.received', 'Hola', 'Hola {{ app_key }}');
})->throws(ValidationException::class);

it('CA-008-5: rejects expressions inside braces instead of evaluating them', function () {
    renderer()->handle('submission.received', 'es', receivedVariables(), body: '{{ config("app.key") }}');
})->throws(ValidationException::class);

it('CA-008-5: a supported variable passes validation', function () {
    renderer()->validate('submission.received', 'Hola {{ author_name }}', 'Folio {{ submission_reference }}');

    expect(true)->toBeTrue();
});

it('CA-008-6: raw HTML, scripts, Blade and PHP in the body are not executed or kept as markup', function () {
    $body = "<script>alert(1)</script>\n\n<img src=x onerror=alert(1)>\n\n@php echo 1; @endphp\n\n{!! \$x !!}\n\n<?php echo 'pwn'; ?>";

    $mail = renderer()->handle('submission.received', 'es', receivedVariables(), body: $body);

    expect($mail->html)->not->toContain('<script')->and($mail->html)->not->toContain('<img')->and($mail->html)->not->toContain('onerror=');
    expect($mail->html)->not->toContain('<?php');
    expect($mail->html)->not->toContain('pwn</');
});

it('CA-008-7: HTML inside a variable value comes out escaped', function () {
    $mail = renderer()->handle('submission.received', 'es', receivedVariables(['submission_title' => '<b>Negrita</b>']));

    expect($mail->html)->toContain('&lt;b&gt;Negrita&lt;/b&gt;')->and($mail->html)->not->toContain('<b>Negrita</b>');
});

it('CA-008-8: a javascript: link in the body is neutralized', function () {
    $mail = renderer()->handle('submission.received', 'es', receivedVariables(), body: '[Haz clic](javascript:alert(1))');

    expect($mail->html)->not->toContain('javascript:');
});

it('CA-008-8: a link with a disallowed protocol (data:, vbscript:) is neutralized', function () {
    $mail = renderer()->handle(
        'submission.received',
        'es',
        receivedVariables(),
        body: "[a](data:text/html;base64,PHNjcmlwdD4=)\n\n[b](vbscript:msgbox(1))",
    );

    expect($mail->html)->not->toContain('data:text/html')->and($mail->html)->not->toContain('vbscript:');
});

it('CA-008-9: a markdown link inside a value is shown literally and creates no link or image', function () {
    $mail = renderer()->handle(
        'submission.new_for_organizers',
        'es',
        organizerVariables(['submission_title' => '[Verifica tu cuenta](https://evil.example)']),
    );

    expect($mail->html)->toContain('[Verifica tu cuenta](https://evil.example)');
    expect($mail->html)->not->toContain('href="https://evil.example"');
    expect($mail->html)->not->toContain('<a href="https://evil');
});

it('CA-008-9: an image or html payload inside a value creates no tag', function () {
    $mail = renderer()->handle(
        'submission.new_for_organizers',
        'es',
        organizerVariables(['author_name' => '![x](https://evil.example/t.png) <iframe src=x></iframe>']),
    );

    expect($mail->html)->not->toContain('<img')->and($mail->html)->not->toContain('<iframe');
});

it('CA-008-10: line breaks in a value cannot add headers to the subject', function () {
    $mail = renderer()->handle(
        'submission.received',
        'es',
        receivedVariables(['event_name' => "Congreso\r\nBcc: otro@example.com"]),
    );

    expect($mail->subject)->not->toContain("\r")->and($mail->subject)->not->toContain("\n");
});

it('CA-008-10: a bare line feed or other control characters are flattened in the subject', function () {
    $mail = renderer()->handle(
        'submission.received',
        'es',
        receivedVariables(['event_name' => "A\nB\x00C\tD"]),
    );

    expect($mail->subject)->not->toMatch('/[\x00-\x1F\x7F]/');
});

it('requires every variable used by the template to have a value', function () {
    $values = receivedVariables();
    unset($values['author_name']);

    renderer()->handle('submission.received', 'es', $values);
})->throws(MailTemplateContextInvalid::class);

it('refuses a url variable that is not an http or https address', function () {
    renderer()->handle(
        'submission.new_for_organizers',
        'es',
        organizerVariables(['panel_url' => 'javascript:alert(1)']),
    );
})->throws(MailTemplateContextInvalid::class);

it('a value that looks like a placeholder is not resolved a second time', function () {
    $mail = renderer()->handle(
        'submission.received',
        'es',
        receivedVariables(['submission_title' => '{{ submission_reference }}']),
    );

    expect($mail->html)->toContain('{{ submission_reference }}');
});

it('renders url variables as clickable links with an http(s) address', function () {
    $mail = renderer()->handle(
        'submission.new_for_organizers',
        'es',
        organizerVariables(),
    );

    expect($mail->html)->toContain('href="https://panel.example.test/proposals/1"');
});
