<?php

declare(strict_types=1);

namespace App\Support\Organizations;

use App\Models\Organization;
use App\Rules\OrganizationSlug;
use Illuminate\Support\Str;

/**
 * Identificador de la organización en la URL, generado a partir de su nombre
 * en el alta desde el panel de plataforma. Cumple las reglas de `OrganizationSlug`
 * (formato, longitud y reservados) y es único también entre las eliminadas,
 * porque el índice único las conserva.
 */
final class OrganizationSlugGenerator
{
    private const FALLBACK = 'organizacion';

    public static function for(string $name): string
    {
        $base = self::base($name);
        $candidate = $base;
        $suffix = 2;

        while (self::unavailable($candidate)) {
            $tail = '-'.$suffix++;
            $candidate = Str::limit($base, OrganizationSlug::MAX_LENGTH - strlen($tail), '').$tail;
        }

        return $candidate;
    }

    private static function base(string $name): string
    {
        $slug = trim(Str::limit(Str::slug($name), OrganizationSlug::MAX_LENGTH, ''), '-');

        // Un nombre sin letras ni números, o demasiado corto, no da un identificador válido.
        return strlen($slug) < OrganizationSlug::MIN_LENGTH ? trim($slug.'-'.self::FALLBACK, '-') : $slug;
    }

    private static function unavailable(string $slug): bool
    {
        return in_array($slug, Organization::RESERVED_SLUGS, true)
            || Organization::withTrashed()->where('slug', $slug)->exists();
    }
}
