<?php

declare(strict_types=1);

namespace App\Support\Files;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Respuesta de una imagen del programa servida al público (plan 021, DA-11, CA-021-18). El disco
 * `media` es privado y no tiene URL pública: solo las rutas públicas de fotos, iconos e imágenes
 * llegan aquí, y solo después de comprobar que el registro está publicado y no está borrado. La caché
 * es corta a propósito: al despublicar un registro, su imagen deja de servirse en minutos, no en días.
 */
final class PublicProgramImage
{
    /** Un archivo que ya no está en el disco responde 404 (no un error de servidor). */
    public static function response(string $path): ?StreamedResponse
    {
        $disk = MediaDisk::get();

        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
