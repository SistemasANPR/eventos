<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\FormDefinition;
use App\Models\SubmissionType;

/** Tipo y formulario ya comprobados con los que se registra un conferencista invitado (plan 018). */
final readonly class InvitationTarget
{
    public function __construct(
        public SubmissionType $type,
        public FormDefinition $form,
    ) {}
}
