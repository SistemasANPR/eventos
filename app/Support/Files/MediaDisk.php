<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Único lugar que conoce el disco de las imágenes del programa (plan 021, DA-11): su nombre sale
 * de `filesystems.media_disk` (`MEDIA_DISK`), de modo que pasar a Google Cloud Storage es un
 * cambio de configuración y no de código.
 */
final class MediaDisk
{
    public static function name(): string
    {
        return (string) config('filesystems.media_disk');
    }

    public static function get(): Filesystem
    {
        return Storage::disk(self::name());
    }
}
