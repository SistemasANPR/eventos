<?php

declare(strict_types=1);

namespace App\Support\Panel;

use Filament\Support\Colors\Color;

/**
 * Paleta del panel de organizadores: los tokens de design.md §3.1 en el formato de Filament.
 *
 * Filament pinta el botón primario con el matiz 600, así que el marino del logotipo
 * (#152B83) ocupa el 600 y el resto de la escala se corre un paso. Los matices
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
            50 => '#f4f7fe', 100 => '#e5edff', 200 => '#cfdbf6', 300 => '#b3c4ea', 400 => '#768fcc',
            500 => '#3a56a6', 600 => '#152b83', 700 => '#0d1e70', 800 => '#08155d', 900 => '#050e40', 950 => '#030824',
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
            50 => '#f8fafe', 100 => '#f1f3f8', 200 => '#e2e6ef', 300 => '#d0d4dc', 400 => '#a1a5ac',
            500 => '#777a82', 600 => '#585b62', 700 => '#3f4249', 800 => '#26292f', 900 => '#14161c', 950 => '#0b0c10',
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
        return self::state('#3a56a6');
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
