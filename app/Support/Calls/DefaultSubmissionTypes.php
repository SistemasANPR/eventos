<?php

declare(strict_types=1);

namespace App\Support\Calls;

use App\Models\Event;
use App\Support\Events\OfferedLocaleTexts;

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

    /**
     * Los nombres solo en los idiomas que ofrece el evento (plan 022, DA-2, CA-022-5).
     *
     * @return list<array<string, string>>
     */
    public static function namesFor(Event $event): array
    {
        return array_map(static fn (array $name): array => OfferedLocaleTexts::for($name, $event), self::NAMES);
    }
}
