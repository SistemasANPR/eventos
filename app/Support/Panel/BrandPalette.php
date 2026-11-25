<?php

declare(strict_types=1);

namespace App\Support\Panel;

use Filament\Support\Colors\Color;

/**
 * Paleta del panel de organizadores: los tokens de design.md §3.1 en el formato de Filament.
 *
 * Filament pinta el botón primario con el matiz 600, así que el marino del logotipo
 * (#012787) ocupa el 600 y el resto de la escala se corre un paso. Los matices
 * 900 y 950 de cada escala no existen en la guía y se derivan más oscuros.
 * `tests/Feature/Panel/PanelThemeTest.php` vigila que sigan igual que `resources/css/app.css`.
 */
final class BrandPalette
{
    /**
     * Marino: acción principal, navegación y enlaces.
     *
     * @return array<int, string>
     */
    public static function primary(): array
    {
        return self::scale([
            50 => '#eef1fa', 100 => '#dde4f6', 200 => '#b9c7f0', 300 => '#8da3de', 400 => '#4f6fc0',
            500 => '#1f46a3', 600 => '#012787', 700 => '#011a5e', 800 => '#01134a', 900 => '#010c33', 950 => '#00071f',
        ]);
    }

    /**
     * Neutro frío: texto, bordes y superficies.
     *
     * @return array<int, string>
     */
    public static function gray(): array
    {
        return self::scale([
            50 => '#fafbfc', 100 => '#f4f6f8', 200 => '#e3e8ec', 300 => '#c5ced6', 400 => '#8e9aa6',
            500 => '#5b6975', 600 => '#4b5a68', 700 => '#364452', 800 => '#243342', 900 => '#16202b', 950 => '#0d141c',
        ]);
    }

    /** Estados (design.md §3.1): el color del semántico es el matiz 600 (texto y botón); el resto lo genera Filament. */
    /** @return array<int, string> */
    public static function danger(): array
    {
        return self::state('#b42318');
    }

    /** @return array<int, string> */
    public static function success(): array
    {
        return self::state('#2e7d32');
    }

    /** @return array<int, string> */
    public static function warning(): array
    {
        return self::state('#92400e');
    }

    /** @return array<int, string> */
    public static function info(): array
    {
        return self::state('#1f46a3');
    }

    /** @return array<int, string> */
    private static function state(string $hex): array
    {
        // array_replace conserva las claves 50 a 950; el operador `...` las renumeraría a 0, 1, 2…
        return array_replace(Color::hex($hex), [600 => self::rgb($hex)]);
    }

    private static function rgb(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return "rgb({$r}, {$g}, {$b})";
    }

    /**
     * @param  array<int, string>  $hexByShade
     * @return array<int, string>
     */
    private static function scale(array $hexByShade): array
    {
        return array_map(
            self::rgb(...),
            $hexByShade,
        );
    }
}
