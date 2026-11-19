<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Qué imagen del programa se sube (plan 021, DA-11): la fotografía de un ponente, la imagen de
 * una actividad o su icono. Cada tipo fija su tope de tamaño y el directorio donde se guarda.
 */
enum ProgramImageType: string
{
    case Photo = 'photo';
    case Image = 'image';
    case Icon = 'icon';
    /** Imagen de la landing del evento (plan 022, DA-7): mismas reglas que la de una actividad. */
    case Landing = 'landing';

    /** Fotografía, imagen y landing hasta 5 MB; icono hasta 256 KB. */
    public function maxBytes(): int
    {
        return match ($this) {
            self::Photo, self::Image, self::Landing => 5 * 1024 * 1024,
            self::Icon => 256 * 1024,
        };
    }

    public function maxSizeLabel(): string
    {
        return match ($this) {
            self::Photo, self::Image, self::Landing => '5 MB',
            self::Icon => '256 KB',
        };
    }
}
