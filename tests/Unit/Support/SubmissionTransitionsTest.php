<?php

declare(strict_types=1);

use App\Enums\SubmissionStatus;
use App\Exceptions\Domain\InvalidSubmissionTransition;
use App\Support\Submissions\SubmissionTransitions;
use Tests\TestCase;

// El mensaje de la excepción usa traducciones: hace falta la aplicación, aunque no haya base de datos.
uses(TestCase::class);

/*
 * Tabla de transiciones de estado de una propuesta (plan 012, DA-3). Lógica pura: sin base de datos.
 */

it('CA-012-2: a submitted proposal can be decided directly', function (SubmissionStatus $to): void {
    expect(SubmissionTransitions::isAllowed(SubmissionStatus::Submitted, $to))->toBeTrue()
        ->and(SubmissionTransitions::isReversal(SubmissionStatus::Submitted, $to))->toBeFalse();
})->with([
    'accepted' => SubmissionStatus::Accepted,
    'rejected' => SubmissionStatus::Rejected,
    'waitlisted' => SubmissionStatus::Waitlisted,
    'under review' => SubmissionStatus::UnderReview,
]);

it('CA-012-1: an under-review proposal can be accepted, waitlisted or rejected', function (SubmissionStatus $to): void {
    expect(SubmissionTransitions::isAllowed(SubmissionStatus::UnderReview, $to))->toBeTrue();
})->with([SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected]);

it('CA-012-1: a waitlisted proposal can be accepted or rejected', function (SubmissionStatus $to): void {
    expect(SubmissionTransitions::isAllowed(SubmissionStatus::Waitlisted, $to))->toBeTrue();
})->with([SubmissionStatus::Accepted, SubmissionStatus::Rejected]);

it('CA-012-3: accepted, waitlisted and rejected go back to under review and that is a reversal', function (SubmissionStatus $from): void {
    expect(SubmissionTransitions::isAllowed($from, SubmissionStatus::UnderReview))->toBeTrue()
        ->and(SubmissionTransitions::isReversal($from, SubmissionStatus::UnderReview))->toBeTrue();
})->with([SubmissionStatus::Accepted, SubmissionStatus::Waitlisted, SubmissionStatus::Rejected]);

it('CA-012-4: every state up to confirmed can be withdrawn', function (SubmissionStatus $from): void {
    expect(SubmissionTransitions::isAllowed($from, SubmissionStatus::Withdrawn))->toBeTrue();
})->with([
    SubmissionStatus::Submitted,
    SubmissionStatus::UnderReview,
    SubmissionStatus::Accepted,
    SubmissionStatus::Waitlisted,
    SubmissionStatus::Rejected,
    SubmissionStatus::Confirmed,
]);

it('CA-012-4: nothing leaves withdrawn', function (SubmissionStatus $to): void {
    expect(SubmissionTransitions::isAllowed(SubmissionStatus::Withdrawn, $to))->toBeFalse()
        ->and(SubmissionTransitions::allowedFrom(SubmissionStatus::Withdrawn))->toBe([]);
})->with(SubmissionStatus::cases());

it('CA-012-4: accepted to rejected directly and other jumps are not allowed', function (SubmissionStatus $from, SubmissionStatus $to): void {
    expect(SubmissionTransitions::isAllowed($from, $to))->toBeFalse()
        ->and(fn () => SubmissionTransitions::assertAllowed($from, $to))->toThrow(InvalidSubmissionTransition::class);
})->with([
    'accepted to rejected' => [SubmissionStatus::Accepted, SubmissionStatus::Rejected],
    'accepted to waitlisted' => [SubmissionStatus::Accepted, SubmissionStatus::Waitlisted],
    'rejected to accepted' => [SubmissionStatus::Rejected, SubmissionStatus::Accepted],
    'waitlisted to waitlisted' => [SubmissionStatus::Waitlisted, SubmissionStatus::Waitlisted],
    'under review to submitted' => [SubmissionStatus::UnderReview, SubmissionStatus::Submitted],
    'confirmed to accepted' => [SubmissionStatus::Confirmed, SubmissionStatus::Accepted],
    'withdrawn to submitted' => [SubmissionStatus::Withdrawn, SubmissionStatus::Submitted],
    'submitted to confirmed' => [SubmissionStatus::Submitted, SubmissionStatus::Confirmed],
]);

it('CA-012-4: the exception carries a stable code', function (): void {
    try {
        SubmissionTransitions::assertAllowed(SubmissionStatus::Accepted, SubmissionStatus::Rejected);
    } catch (InvalidSubmissionTransition $exception) {
        expect($exception->getMessage())->toContain('INVALID_SUBMISSION_TRANSITION')
            ->and(InvalidSubmissionTransition::CODE)->toBe('INVALID_SUBMISSION_TRANSITION');

        return;
    }

    $this->fail('No se lanzó la excepción.');
});

it('CA-012-4: assertAllowed does nothing for a permitted transition', function (): void {
    SubmissionTransitions::assertAllowed(SubmissionStatus::Submitted, SubmissionStatus::Accepted);

    expect(true)->toBeTrue();
});
