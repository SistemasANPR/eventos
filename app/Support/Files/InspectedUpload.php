<?php

declare(strict_types=1);

namespace App\Support\Files;

use Illuminate\Http\UploadedFile;

/**
 * Archivo ya comprobado por contenido: lo que se sabe de él sin fiarse de lo que
 * declaró el cliente. `originalName` ya está saneado y es solo para mostrar.
 */
final readonly class InspectedUpload
{
    public function __construct(
        public UploadedFile $file,
        public string $extension,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
        public string $originalName,
    ) {}
}
