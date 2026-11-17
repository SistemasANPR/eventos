<?php

declare(strict_types=1);

use App\Actions\Forms\BuildSubmissionRules;
use App\Support\Forms\PendingUploadRepository;

require_once __DIR__.'/../../Support/form-helpers.php';

/**
 * Doble del contrato del plan 007: solo reconoce las cargas que se le indican, ligadas a un token y una organización.
 *
 * @param  list<string>  $owned
 */
function pendingUploads(array $owned = [], string $token = 'token-1', string $organizationId = 'org-1'): PendingUploadRepository
{
    return new class($owned, $token, $organizationId) implements PendingUploadRepository
    {
        /** @param list<string> $owned */
        public function __construct(private array $owned, private string $token, private string $organizationId) {}

        public function allBelongTo(array $uploadIds, string $uploadToken, string $organizationId): bool
        {
            return $uploadToken === $this->token
                && $organizationId === $this->organizationId
                && array_diff($uploadIds, $this->owned) === [];
        }
    };
}

/**
 * Un campo tal como queda guardado: con clave, posición y valores de opción ya generados.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function storedField(string $key, string $type, array $overrides = []): array
{
    $field = formField($type, array_replace(['key' => $key, 'position' => 1, 'retiredAt' => null], $overrides));

    if (isset($field['config']['options'])) {
        foreach ($field['config']['options'] as $index => $option) {
            $field['config']['options'][$index]['value'] ??= 'o_'.($index + 1);
        }
    }

    return $field;
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @param  array<string, mixed>  $answers
 * @return array<string, mixed>
 */
function validateAnswers(array $fields, array $answers, ?PendingUploadRepository $uploads = null): array
{
    return (new BuildSubmissionRules($uploads ?? pendingUploads()))->validate($fields, $answers, 'token-1', 'org-1');
}

/**
 * @param  list<array<string, mixed>>  $fields
 * @param  array<string, mixed>  $answers
 * @return list<string>
 */
function answerErrors(array $fields, array $answers, ?PendingUploadRepository $uploads = null): array
{
    return validationErrorKeys(fn () => validateAnswers($fields, $answers, $uploads));
}

it('CA-005-25: a missing or empty required answer fails on the key of that field', function (mixed $answer): void {
    $fields = [storedField('f_titulo', 'short_text'), storedField('f_resumen', 'long_text')];

    expect(answerErrors($fields, ['f_titulo' => $answer, 'f_resumen' => 'Texto']))->toBe(['answers.f_titulo']);
})->with([
    'empty string' => [''],
    'null' => [null],
]);

it('CA-005-25: an absent required answer fails and an absent optional one passes', function (): void {
    $fields = [storedField('f_titulo', 'short_text'), storedField('f_nota', 'short_text', ['required' => false])];

    expect(answerErrors($fields, []))->toBe(['answers.f_titulo'])
        ->and(validateAnswers($fields, ['f_titulo' => 'Hola']))->toBe(['f_titulo' => 'Hola']);
});

it('CA-005-26: text above maxLength or below minLength is rejected', function (string $text): void {
    $fields = [storedField('f_t', 'short_text', ['config' => ['minLength' => 3, 'maxLength' => 5]])];

    expect(answerErrors($fields, ['f_t' => $text]))->toBe(['answers.f_t']);
})->with(['too long' => ['abcdef'], 'too short' => ['ab']]);

it('CA-005-26: text inside its limits is accepted', function (): void {
    $fields = [storedField('f_t', 'short_text', ['config' => ['minLength' => 3, 'maxLength' => 5]])];

    expect(validateAnswers($fields, ['f_t' => 'abcde']))->toBe(['f_t' => 'abcde']);
});

it('CA-005-26: a number that is not numeric or is out of range is rejected', function (mixed $value): void {
    $fields = [storedField('f_n', 'number', ['config' => ['min' => 1, 'max' => 10]])];

    expect(answerErrors($fields, ['f_n' => $value]))->toBe(['answers.f_n']);
})->with(['text' => ['diez'], 'below' => [0], 'above' => [11], 'array' => [[5]]]);

it('CA-005-26: numbers on the limits, as numbers or numeric strings, are accepted', function (mixed $value): void {
    $fields = [storedField('f_n', 'number', ['config' => ['min' => 1, 'max' => 10]])];

    expect(answerErrors($fields, ['f_n' => $value]))->toBe([]);
})->with([1, 10, '5', '7.5']);

it('CA-005-27: a single choice must be one of the option values', function (): void {
    $fields = [storedField('f_s', 'single_choice')];

    expect(answerErrors($fields, ['f_s' => 'o_1']))->toBe([])
        ->and(answerErrors($fields, ['f_s' => 'o_99']))->toBe(['answers.f_s'])
        ->and(answerErrors($fields, ['f_s' => ['o_1']]))->toBe(['answers.f_s']);
});

it('CA-005-27: a multiple choice rejects values outside the options, repeats and too many selections', function (array $value, array $errors): void {
    $fields = [storedField('f_m', 'multiple_choice')];

    expect(answerErrors($fields, ['f_m' => $value]))->toBe($errors);
})->with([
    'valid' => [['o_1', 'o_2'], []],
    'unknown option' => [['o_1', 'o_99'], ['answers.f_m.1']],
    'repeated' => [['o_1', 'o_1'], ['answers.f_m.0', 'answers.f_m.1']],
    'above maxSelections' => [['o_1', 'o_2', 'o_3'], ['answers.f_m']],
]);

it('CA-005-28: a required checkbox rejects false or absent and an optional one accepts false', function (): void {
    $required = [storedField('f_c', 'checkbox')];
    $optional = [storedField('f_c', 'checkbox', ['required' => false])];

    expect(answerErrors($required, ['f_c' => false]))->toBe(['answers.f_c'])
        ->and(answerErrors($required, []))->toBe(['answers.f_c'])
        ->and(answerErrors($required, ['f_c' => true]))->toBe([])
        ->and(answerErrors($optional, ['f_c' => false]))->toBe([])
        ->and(answerErrors($optional, ['f_c' => 'tal vez']))->toBe(['answers.f_c']);
});

it('CA-005-29: a date must be a valid civil date in ISO format', function (string $date, bool $valid): void {
    $fields = [storedField('f_d', 'date')];

    expect(answerErrors($fields, ['f_d' => $date]))->toBe($valid ? [] : ['answers.f_d']);
})->with([
    'valid' => ['2027-03-10', true],
    'leap day' => ['2028-02-29', true],
    'not a leap year' => ['2027-02-29', false],
    'month 13' => ['2027-13-01', false],
    'day 31 of april' => ['2027-04-31', false],
    'other format' => ['10/03/2027', false],
    'with time' => ['2027-03-10 10:00', false],
    'text' => ['mañana', false],
]);

it('CA-005-30: a country must be an ISO 3166-1 alpha-2 code from the system list', function (string $code, bool $valid): void {
    $fields = [storedField('f_p', 'country')];

    expect(answerErrors($fields, ['f_p' => $code]))->toBe($valid ? [] : ['answers.f_p']);
})->with([
    'Mexico' => ['MX', true],
    'Peru' => ['PE', true],
    'Brazil' => ['BR', true],
    'lowercase' => ['mx', false],
    'alpha-3' => ['MEX', false],
    'not assigned' => ['XX', false],
    'name' => ['México', false],
]);

it('CA-005-31: a key that is not in the definition or belongs to a retired field is rejected and never returned', function (): void {
    $fields = [
        storedField('f_titulo', 'short_text'),
        storedField('f_viejo', 'short_text', ['required' => true, 'retiredAt' => '2027-01-01T00:00:00Z']),
    ];

    expect(answerErrors($fields, ['f_titulo' => 'Hola', 'f_inventado' => 'x']))->toBe(['answers.f_inventado'])
        ->and(answerErrors($fields, ['f_titulo' => 'Hola', 'f_viejo' => 'x']))->toBe(['answers.f_viejo'])
        ->and(validateAnswers($fields, ['f_titulo' => 'Hola']))->toBe(['f_titulo' => 'Hola']);
});

it('CA-005-31: a retired field is not required for anybody', function (): void {
    $fields = [storedField('f_viejo', 'short_text', ['required' => true, 'retiredAt' => '2027-01-01T00:00:00Z'])];

    expect(answerErrors($fields, []))->toBe([]);
});

it('CA-005-32: a file field only accepts pending uploads of the same token and organization', function (): void {
    $fields = [storedField('f_a', 'file', ['config' => ['accept' => ['pdf'], 'maxFiles' => 2]])];
    $uploads = pendingUploads(['u1', 'u2']);

    expect(answerErrors($fields, ['f_a' => ['u1']], $uploads))->toBe([])
        ->and(answerErrors($fields, ['f_a' => ['u1', 'u2']], $uploads))->toBe([])
        ->and(answerErrors($fields, ['f_a' => ['u9']], $uploads))->toBe(['answers.f_a'])
        ->and(answerErrors($fields, ['f_a' => 'u1'], $uploads))->toBe(['answers.f_a']);
});

it('CA-005-32: a file field rejects more files than maxFiles and uploads of another token or organization', function (): void {
    $fields = [storedField('f_a', 'file', ['config' => ['accept' => ['pdf'], 'maxFiles' => 1]])];

    expect(answerErrors($fields, ['f_a' => ['u1', 'u2']], pendingUploads(['u1', 'u2'])))->toBe(['answers.f_a'])
        ->and(answerErrors($fields, ['f_a' => ['u1']], pendingUploads(['u1'], token: 'otro-token')))->toBe(['answers.f_a'])
        ->and(answerErrors($fields, ['f_a' => ['u1']], pendingUploads(['u1'], organizationId: 'otra-org')))->toBe(['answers.f_a']);
});

it('CA-005-33: control characters are removed and the text is not trusted for display', function (): void {
    $fields = [storedField('f_t', 'long_text')];

    $clean = validateAnswers($fields, ['f_t' => "Hola\x00 mun\x07do\x7F<b>x</b>"]);

    expect($clean['f_t'])->toBe('Hola mundo<b>x</b>');
});

it('CA-005-33: the limit counts the text after removing control characters', function (): void {
    $fields = [storedField('f_t', 'short_text', ['config' => ['maxLength' => 4]])];

    expect(answerErrors($fields, ['f_t' => "ab\x00cd"]))->toBe([])
        ->and(answerErrors($fields, ['f_t' => "ab\x00cde"]))->toBe(['answers.f_t']);
});

it('CA-005-33: a set of answers above 256 KB is rejected', function (): void {
    $fields = [
        storedField('f_a', 'long_text', ['required' => false, 'config' => ['maxLength' => 10000]]),
        storedField('f_b', 'multiple_choice', ['required' => false]),
    ];
    $big = str_repeat('a', 10000);
    $answers = ['f_a' => $big, 'f_b' => ['o_1']];

    expect(answerErrors($fields, $answers))->toBe([]);

    $many = [];

    for ($i = 0; $i < 40; $i++) {
        $many[] = storedField('f_'.$i, 'long_text', ['required' => false, 'config' => ['maxLength' => 10000]]);
    }

    $everything = array_fill_keys(array_column($many, 'key'), $big);

    expect(strlen((string) json_encode($everything)))->toBeGreaterThan(262144)
        ->and(answerErrors($many, $everything))->toBe(['answers']);
});

it('CA-005-34: creating and editing a submission share the same rules', function (): void {
    $builder = new BuildSubmissionRules(pendingUploads());
    $fields = [storedField('f_titulo', 'short_text'), storedField('f_pais', 'country')];
    $answers = ['f_titulo' => 'Hola', 'f_pais' => 'MX'];

    expect($builder->validate($fields, $answers, 'token-1', 'org-1'))
        ->toBe($builder->validate($fields, $answers, 'token-1', 'org-1'))
        ->and($builder->handle($fields, 'token-1', 'org-1'))->toHaveKeys(['answers.f_titulo', 'answers.f_pais']);
});
