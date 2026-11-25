<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Enums\SubmissionFileStatus;
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

    /**
     * Posición (desde 1) de cada archivo ya ligado a la propuesta, por orden de subida: la numeración de los
     * nombres neutros que ve el evaluador, en pantalla (`archivo-{n}`) y al descargar (`propuesta-{n}`).
     * Exige la organización en contexto.
     *
     * @return array<string, int> posición por identificador de archivo
     */
    public static function positions(string $submissionId): array
    {
        $ids = SubmissionFile::query()
            ->where('submission_id', $submissionId)
            ->where('status', SubmissionFileStatus::Attached)
            ->orderBy('uploaded_at')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_map(static fn (int $index): int => $index + 1, array_flip($ids));
    }

    /**
     * Nombre de descarga neutro del evaluador (plan 011, CA-011-20): `propuesta-{n}.{ext}`, sin nada del
     * original. Se construye solo con datos generados por el servidor, como pide `PrivateUploads::temporaryUrl`.
     */
    public static function neutralDownloadName(SubmissionFile $file): string
    {
        $position = $file->submission_id === null ? 1 : (self::positions($file->submission_id)[$file->id] ?? 1);

        return 'propuesta-'.$position.'.'.self::extension($file);
    }

    /** Extensión generada por el servidor al guardar (lista cerrada de `PrivateUploads::ALLOWED`). */
    public static function extension(SubmissionFile $file): string
    {
        return strtolower(pathinfo($file->storage_path, PATHINFO_EXTENSION));
    }
}
