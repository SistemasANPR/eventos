<?php

declare(strict_types=1);

namespace App\Actions\Program;

use App\Enums\ProgramImageType;
use App\Exceptions\Domain\PrivateUploadFailed;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Files\MediaDisk;
use App\Support\Files\PrivateUploads;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda la fotografía de un ponente, la imagen de una actividad o su icono en el disco `media`
 * (plan 021, DA-11, CA-021-3). Decide por el CONTENIDO: nada de lo que declara el cliente (nombre,
 * extensión, tipo MIME) se toma como cierto. Solo JPEG, PNG y WebP que el decodificador de imágenes
 * entiende, sin código incrustado y dentro del tope del tipo (5 MB, icono 256 KB). SVG queda fuera.
 *
 * El nombre en disco lo genera el servidor (uuid + extensión derivada del contenido) bajo
 * `organizations/{organización}/events/{evento}/media/{tipo}/`; se devuelve la RUTA, no la URL.
 * Un archivo rechazado no deja nada en el disco. Esta Action no autoriza: la llaman las Actions
 * de ponentes y actividades, que ya comprobaron el permiso sobre el evento.
 *
 * @throws ValidationException (campo `file`) si el archivo no es aceptable
 * @throws PrivateUploadFailed si el disco no lo guarda
 */
final class StoreProgramImage
{
    /** Tipo real (finfo) => extensión con la que se guarda. */
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /** Tope de píxeles contra imágenes diminutas en bytes que se expanden en memoria (bomba de descompresión). */
    private const MAX_PIXELS = 40_000_000;

    public function handle(UploadedFile $file, Event $event, ProgramImageType $type): string
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($file, $event, $type): string {
            $extension = $this->extensionOf($file, $type);
            $path = TenantStoragePath::for("events/{$event->id}/media/{$type->value}/".Str::uuid().".{$extension}");

            $stored = MediaDisk::get()->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);

            if ($stored === false) {
                throw PrivateUploadFailed::onDisk(MediaDisk::name());
            }

            return $path;
        });
    }

    /** @throws ValidationException */
    private function extensionOf(UploadedFile $file, ProgramImageType $type): string
    {
        $path = $file->getRealPath();

        if (! $file->isValid() || $path === false) {
            throw $this->rejected('files.upload_failed');
        }

        $size = (int) filesize($path);

        if ($size === 0) {
            throw $this->rejected('files.empty');
        }

        if ($size > $type->maxBytes()) {
            throw $this->rejected('files.too_large', ['max' => $type->maxSizeLabel()]);
        }

        $mime = $this->detectMime($path);

        if ($mime === null || ! isset(self::EXTENSIONS[$mime])) {
            throw $this->rejected('files.image_type_not_allowed');
        }

        // Un archivo con cabecera de imagen pero corrupto, o que dice ser otra cosa, no pasa el decodificador.
        $info = @getimagesize($path);

        if ($info === false || $info[2] === 0 || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) {
            throw $this->rejected('files.image_invalid');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw $this->rejected('files.image_invalid');
        }

        if (PrivateUploads::isPolyglot($path)) {
            throw $this->rejected('files.suspicious_content');
        }

        return self::EXTENSIONS[$mime];
    }

    private function detectMime(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $detected = finfo_file($finfo, $path);

        return $detected === false ? null : $detected;
    }

    /** @param  array<string, string>  $replace */
    private function rejected(string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['file' => __($key, $replace)]);
    }
}
