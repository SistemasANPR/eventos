<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Model;

/**
 * Parte una selección de propuestas en las que pueden pasar al estado elegido y las que no (plan 012, F2,
 * CA-012-9 y 10). La pantalla muestra antes de confirmar cuántas se afectarán por estado y cuántas se
 * omitirán, y solo manda a la Action las elegibles; la Action vuelve a comprobarlo todo contra la base.
 */
final readonly class BulkDecisionSelection
{
    /**
     * @param  list<Submission>  $eligible
     * @param  list<Submission>  $omitted
     */
    public function __construct(
        public SubmissionStatus $to,
        public array $eligible,
        public array $omitted,
    ) {}

    /** @param  iterable<Model>  $submissions la selección de la lista (solo se consideran propuestas) */
    public static function for(iterable $submissions, SubmissionStatus $to): self
    {
        $eligible = [];
        $omitted = [];

        foreach ($submissions as $submission) {
            if (! $submission instanceof Submission) {
                continue;
            }

            if ($submission->origin === SubmissionOrigin::Call && SubmissionTransitions::isAllowed($submission->status, $to)) {
                $eligible[] = $submission;
            } else {
                $omitted[] = $submission;
            }
        }

        return new self($to, $eligible, $omitted);
    }

    /** @return list<string> */
    public function eligibleIds(): array
    {
        return array_map(static fn (Submission $submission): string => $submission->id, $this->eligible);
    }

    /** @return array<string, int> propuestas elegibles por su estado actual */
    public function eligibleByStatus(): array
    {
        return self::countByStatus($this->eligible);
    }

    /** @return array<string, int> propuestas omitidas por su estado actual */
    public function omittedByStatus(): array
    {
        return self::countByStatus($this->omitted);
    }

    /**
     * @param  list<Submission>  $submissions
     * @return array<string, int>
     */
    private static function countByStatus(array $submissions): array
    {
        $counts = [];

        foreach ($submissions as $submission) {
            $label = $submission->status->getLabel();
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
