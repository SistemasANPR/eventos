<?php

declare(strict_types=1);

namespace App\Support\Panel;

use App\Models\Organization;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar de iniciales generado en el servidor, como imagen SVG incrustada (`data:`).
 *
 * Sustituye al de Filament, que pide la imagen a ui-avatars.com y le envía el nombre de la persona:
 * el panel no contacta a terceros (docs/privacidad.md). Persona en azul de texto con letras blancas;
 * organización en lima con letras azules (la lima nunca lleva texto blanco, design.md §3.1).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    private const PERSON_BACKGROUND = '#243342';

    private const PERSON_TEXT = '#ffffff';

    private const ORGANIZATION_BACKGROUND = '#d2dd0a';

    private const ORGANIZATION_TEXT = '#012787';

    public function get(Model $record): string
    {
        [$background, $text] = $record instanceof Organization
            ? [self::ORGANIZATION_BACKGROUND, self::ORGANIZATION_TEXT]
            : [self::PERSON_BACKGROUND, self::PERSON_TEXT];

        $initials = htmlspecialchars($this->initials(Filament::getNameForDefaultAvatar($record)), ENT_QUOTES | ENT_XML1);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            ."<rect width=\"64\" height=\"64\" fill=\"{$background}\"/>"
            ."<text x=\"50%\" y=\"50%\" dy=\".35em\" text-anchor=\"middle\" fill=\"{$text}\" "
            .'font-family="Roboto, Arial, sans-serif" font-size="26" font-weight="700">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;charset=utf-8,'.rawurlencode($svg);
    }

    /** Hasta dos iniciales de las palabras con más de dos letras («de», «y»… no cuentan). */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $significant = array_values(array_filter(
            $words,
            static fn (string $word): bool => mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $word) ?? '') > 2,
        ));
        $source = $significant !== [] ? $significant : $words;

        $letters = array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $word) ?? '', 0, 1)),
            array_slice($source, 0, 2),
        );

        return implode('', $letters);
    }
}
