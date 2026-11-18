<?php

declare(strict_types=1);

namespace App\Support\Program;

/**
 * Lo que dejó `HideProgramOfSubmission` (plan 021, DA-3, F5): la actividad y los ponentes que se
 * ocultaron y los que siguen visibles por estar en otra actividad publicada. La pantalla de quien
 * revierte muestra `notice()`.
 */
final readonly class ProgramReversal
{
    /** Verdadero si esta ejecución ocultó algo; una repetición idempotente lo deja en falso. */
    public bool $changed;

    public function __construct(
        public ?string $activityTitle,
        public bool $activityHidden,
        public int $speakersHidden,
        public int $speakersKept,
    ) {
        $this->changed = $activityHidden || $speakersHidden > 0;
    }

    /** Texto del aviso en el idioma activo; vacío si no se ocultó nada. */
    public function notice(): string
    {
        if (! $this->changed) {
            return '';
        }

        $message = $this->activityHidden && $this->activityTitle !== null
            ? __('program.reversal.notice', ['activity' => $this->activityTitle, 'speakers' => $this->speakersHidden])
            : __('program.reversal.notice_without_activity', ['speakers' => $this->speakersHidden]);

        return $this->speakersKept > 0
            ? $message.' '.__('program.reversal.kept', ['kept' => $this->speakersKept])
            : $message;
    }
}
