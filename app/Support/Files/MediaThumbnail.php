<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Support\Tenancy\TenantCacheKey;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Miniatura de una imagen del programa para el PANEL del organizador (plan 021, CA-021-18): el disco
 * `media` es privado, así que la vista previa no es una URL pública sino una miniatura incrustada
 * (`data:`) hecha en el servidor. No hace pública ninguna imagen ni abre una ruta nueva. La ruta del
 * archivo lleva un uuid generado y no se reutiliza, así que la miniatura se guarda en caché sin
 * riesgo de quedar vieja; la clave lleva la organización en contexto (ADR-0003).
 */
final class MediaThumbnail
{
    /** @param  int  $size  lado máximo en píxeles */
    public static function dataUri(?string $path, int $size = 96): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Cache::remember(
            TenantCacheKey::for("media-thumbnail:{$size}:{$path}"),
            now()->addDay(),
            static fn (): ?string => self::render($path, $size),
        );
    }

    private static function render(string $path, int $size): ?string
    {
        try {
            $contents = MediaDisk::get()->get($path);
            $image = is_string($contents) ? imagecreatefromstring($contents) : false;

            if ($image === false) {
                return null;
            }

            $thumbnail = imagescale($image, $size);

            if ($thumbnail === false) {
                return null;
            }

            // Sobre fondo blanco: JPEG no tiene transparencia y un PNG transparente saldría negro.
            $width = imagesx($thumbnail);
            $height = imagesy($thumbnail);
            $canvas = imagecreatetruecolor($width, $height);

            if ($canvas === false) {
                return null;
            }

            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $thumbnail, 0, 0, 0, 0, $width, $height);

            ob_start();
            imagejpeg($canvas, null, 80);

            return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        } catch (Throwable $exception) {
            // Una miniatura que no se puede hacer no debe tumbar la lista; el fallo queda en el registro.
            report($exception);

            return null;
        }
    }
}
