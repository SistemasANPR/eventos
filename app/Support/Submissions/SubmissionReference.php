<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\Submission;

/**
 * Referencia corta de una propuesta para mostrarla al autor y en los correos (acuse, enlace de acceso).
 * Solo son los primeros caracteres del UUID: no es un secreto ni concede acceso a nada.
 */
final class SubmissionReference
{
    public static function for(Submission $submission): string
    {
        return strtoupper(substr(str_replace('-', '', $submission->id), 0, 8));
    }
}
