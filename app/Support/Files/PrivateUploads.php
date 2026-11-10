<?php

declare(strict_types=1);

namespace App\Support\Files;

use App\Exceptions\Domain\PrivateUploadFailed;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Guarda los archivos de usuarios en el disco PRIVADO y decide, por su
 * CONTENIDO, si se aceptan (plan 007; `docs/seguridad.md` §5). Nada de lo que
 * declara el cliente (nombre, tipo MIME, extensión) se toma como cierto por sí
 * solo: la extensión debe estar en la lista cerrada, el tipo real (finfo) debe
 * corresponder a ella y el contenido no puede ser un políglota.
 *
 * El nombre en disco lo genera el servidor; el nombre original solo se conserva,
 * saneado, como dato para mostrar.
 */
class PrivateUploads
{
    /** 25 MB por archivo (DA-2). */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * Lista cerrada de tipos (DA-1): extensión normalizada => tipos MIME reales aceptados.
     * Un PPTX es un ZIP: finfo puede verlo como `application/zip`, y su estructura se comprueba aparte.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** @var array<string, string> */
    private const EXTENSION_ALIASES = ['jpeg' => 'jpg'];

    private const MAX_NAME_LENGTH = 255;

    private const MAX_ZIP_ENTRIES = 10000;

    /** Fin del directorio central de un ZIP: debe quedar en los últimos 64 KB + 22 bytes del archivo. */
    private const ZIP_END_SIGNATURE = "PK\x05\x06";

    private const ZIP_END_WINDOW = 65557;

    private const SCAN_CHUNK_BYTES = 1024 * 1024;

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('filesystems.private_uploads_disk'));
    }

    /**
     * Comprueba el archivo sin guardarlo.
     *
     * @throws ValidationException (campo `file`) si el archivo no es aceptable
     */
    public function inspect(UploadedFile $file): InspectedUpload
    {
        $path = $file->getRealPath();

        if (! $file->isValid() || $path === false) {
            throw $this->rejected('files.upload_failed');
        }

        $size = (int) filesize($path);

        if ($size > self::MAX_BYTES) {
            throw $this->rejected('files.too_large', ['max' => self::maxSizeLabel()]);
        }

        if ($size === 0) {
            throw $this->rejected('files.empty');
        }

        $originalName = self::sanitizeName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = self::EXTENSION_ALIASES[$extension] ?? $extension;

        if (! array_key_exists($extension, self::ALLOWED)) {
            throw $this->rejected('files.type_not_allowed', ['types' => self::allowedTypesLabel()]);
        }

        $mime = $this->detectMime($path);

        if ($mime === null || ! in_array($mime, self::ALLOWED[$extension], true)) {
            throw $this->rejected('files.content_mismatch');
        }

        if ($extension === 'pptx' ? ! $this->hasPresentationStructure($path) : $this->isPolyglot($path)) {
            throw $this->rejected($extension === 'pptx' ? 'files.invalid_presentation' : 'files.suspicious_content');
        }

        return new InspectedUpload(
            file: $file,
            extension: $extension,
            mimeType: $mime,
            sizeBytes: $size,
            sha256: (string) hash_file('sha256', $path),
            originalName: $originalName,
        );
    }

    /**
     * Guarda el archivo comprobado en `organizations/{organización}/events/{evento}/files/{id}.{ext}`.
     * Exige contexto de organización. Devuelve la ruta relativa al disco.
     *
     * @throws PrivateUploadFailed si el disco no lo guarda
     */
    public function store(InspectedUpload $upload, string $eventId, string $id): string
    {
        $path = TenantStoragePath::for("events/{$eventId}/files/{$id}.{$upload->extension}");
        $stored = $this->disk()->putFileAs(dirname($path), $upload->file, basename($path), ['visibility' => 'private']);

        if ($stored === false) {
            throw PrivateUploadFailed::onDisk((string) config('filesystems.private_uploads_disk'));
        }

        return $path;
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * Nombre para mostrar: sin segmentos de ruta (`../`, `\`), sin caracteres de
     * control ni de formato (p. ej. el que invierte el sentido del texto) y de
     * a lo sumo 255 caracteres. Nunca se usa como ruta ni como cabecera.
     */
    public static function sanitizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $separator = strrpos($name, '/');
        $name = $separator === false ? $name : substr($name, $separator + 1);
        $name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name));

        if ($name === '' || $name === '.' || $name === '..') {
            return 'archivo';
        }

        return mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }

    public static function maxSizeLabel(): string
    {
        return (self::MAX_BYTES / 1024 / 1024).' MB';
    }

    public static function allowedTypesLabel(): string
    {
        return strtoupper(implode(', ', array_keys(self::ALLOWED)));
    }

    private function detectMime(string $path): ?string
    {
        $mime = finfo_open(FILEINFO_MIME_TYPE);

        if ($mime === false) {
            return null;
        }

        $detected = finfo_file($mime, $path);

        return $detected === false ? null : $detected;
    }

    /** OOXML mínimo: un ZIP con `[Content_Types].xml` y al menos una entrada bajo `ppt/`. */
    private function hasPresentationStructure(string $path): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if ($zip->numFiles > self::MAX_ZIP_ENTRIES || $zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                if (str_starts_with((string) $zip->getNameIndex($index), 'ppt/')) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Un PDF, JPG o PNG legítimo no lleva código PHP ni un ZIP pegado al final:
     * se rechaza `<?php` en cualquier punto y el fin de directorio central de un ZIP al final.
     */
    private function isPolyglot(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return true;
        }

        try {
            $carry = '';
            while (! feof($handle)) {
                $chunk = $carry.(string) fread($handle, self::SCAN_CHUNK_BYTES);
                if (stripos($chunk, '<?php') !== false) {
                    return true;
                }
                $carry = substr($chunk, -4);
            }

            $size = (int) filesize($path);
            fseek($handle, max(0, $size - self::ZIP_END_WINDOW));

            return str_contains((string) fread($handle, self::ZIP_END_WINDOW), self::ZIP_END_SIGNATURE);
        } finally {
            fclose($handle);
        }
    }

    /** @param  array<string, string>  $replace */
    private function rejected(string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['file' => __($key, $replace)]);
    }
}
