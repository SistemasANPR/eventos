<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\Submission;

/**
 * Resultado de `SubmitSubmission`. `created` es `false` cuando la clave de idempotencia ya
 * había producido esta propuesta: quien llama muestra la misma confirmación pero no repite
 * efectos (correos, por ejemplo).
 */
final readonly class SubmitOutcome
{
    public function __construct(public Submission $submission, public bool $created) {}
}
