<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Models\SubmissionFile;

/**
 * Datos de un archivo de propuesta listos para mostrar (plan 007, CA-007-23).
 * Nunca incluyen la ruta de almacenamiento, el hash del token ni el contenido.
 */
final class SubmissionFileData
{
    /**
     * Para roles que no deben ver la identidad del autor (evaluador ciego): el
     * nombre original puede delatarla, así que solo ven `archivo-{n}.{ext}`.
     *
     * @return array{id: string, name: string, mime_type: string, size_bytes: int}
     */
    public static function forReviewer(SubmissionFile $file, int $position): array
    {
        return self::build($file, 'archivo-'.$position.'.'.self::extension($file));
    }

    /**
     * Para organizadores: el nombre original ESCAPADO, solo para mostrar. No es
     * un nombre de descarga ni una ruta (la descarga usa el nombre generado).
     *
     * @return array{id: string, name: string, mime_type: string, size_bytes: int}
     */
    public static function forOrganizer(SubmissionFile $file): array
    {
        return self::build($file, e($file->original_name));
    }

    /** @return array{id: string, name: string, mime_type: string, size_bytes: int} */
    private static function build(SubmissionFile $file, string $name): array
    {
        return [
            'id' => $file->id,
            'name' => $name,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
        ];
    }

    /** Extensión generada por el servidor al guardar (lista cerrada de `PrivateUploads::ALLOWED`). */
    public static function extension(SubmissionFile $file): string
    {
        return strtolower(pathinfo($file->storage_path, PATHINFO_EXTENSION));
    }
}
