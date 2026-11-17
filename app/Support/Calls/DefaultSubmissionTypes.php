<?php

declare(strict_types=1);

namespace App\Support\Calls;

/** Tipos de propuesta con los que nace cada evento (plan 004, DA-7, CA-004-29), en su orden. */
final class DefaultSubmissionTypes
{
    /** @var list<array<string, string>> */
    public const NAMES = [
        ['es' => 'Sesión', 'en' => 'Session', 'pt' => 'Sessão'],
        ['es' => 'Panel', 'en' => 'Panel', 'pt' => 'Painel'],
        ['es' => 'Póster', 'en' => 'Poster', 'pt' => 'Pôster'],
        ['es' => 'Taller', 'en' => 'Workshop', 'pt' => 'Oficina'],
    ];
}
