<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Models\EventActivity;
use App\Models\Speaker;

/**
 * Lo que dejó `PromoteSubmissionToProgram` (plan 021, F5): la actividad de la propuesta (nula solo si el
 * organizador la eliminó), sus ponentes en el orden de los participantes y qué se creó o cambió en esta
 * ejecución. `changed` es falso en una repetición idempotente.
 */
final readonly class ProgramPromotion
{
    /** @param  list<Speaker>  $speakers */
    public function __construct(
        public ?EventActivity $activity,
        public array $speakers,
        public int $speakersCreated,
        public int $speakersReused,
        public bool $activityCreated,
        public bool $changed,
    ) {}
}
