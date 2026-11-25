<?php

declare(strict_types=1);

namespace App\Support\Lodgings;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use App\Models\Lodging;
use App\Models\LodgingImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Galería de un hospedaje para `CreateLodging`, `UpdateLodging` y `DeleteLodging` (plan 030, DA-5, CA-030-14):
 * hasta `MAX_IMAGES` imágenes por hotel, cada una JPG, PNG o WebP de hasta 2 MB, guardadas en el disco `media`
 * por su contenido. Las filas nuevas se ordenan al final; quitar una no reordena las demás.
 */
trait StoresLodgingImages
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<UploadedFile> los archivos subidos que trae `$data['images']`, en su orden
     */
    private function uploadedImages(array $data): array
    {
        $images = $data['images'] ?? [];

        return array_values(array_filter(
            is_array($images) ? $images : [$images],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
    }

    /**
     * Rechaza antes de tocar el disco si la galería quedaría con más de `MAX_IMAGES` imágenes.
     *
     * @throws ValidationException
     */
    private function assertWithinLimit(int $kept, int $added): void
    {
        if ($kept + $added > LodgingRules::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => __('lodgings.images.too_many', ['max' => LodgingRules::MAX_IMAGES]),
            ]);
        }
    }

    /**
     * Guarda cada archivo y crea su fila al final de la galería. Anota cada ruta guardada en `$stored` para que,
     * si algo falla después, quien llama borre los archivos huérfanos.
     *
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $stored  (por referencia) rutas ya guardadas
     *
     * @throws ValidationException
     */
    private function attachImages(Lodging $lodging, Event $event, array $files, array &$stored): void
    {
        $position = (int) LodgingImage::query()->where('lodging_id', $lodging->id)->max('position');

        foreach ($files as $file) {
            try {
                $path = app(StoreProgramImage::class)->handle($file, $event, ProgramImageType::LodgingImage);
            } catch (ValidationException $exception) {
                // El error de `StoreProgramImage` (campo `file`) se informa en el campo `images`.
                throw ValidationException::withMessages(['images' => $exception->errors()['file'] ?? $exception->getMessage()]);
            }

            $stored[] = $path;

            $image = new LodgingImage;
            $image->event_id = $event->id;
            $image->forceFill(['lodging_id' => $lodging->id, 'path' => $path, 'position' => ++$position])->save();
        }
    }

    /**
     * Borra del disco los archivos que ya no se usan. Un fallo al borrar se registra y no deshace lo guardado.
     *
     * @param  list<string>  $paths
     */
    private function discardImages(Event $event, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                app(DeleteProgramImage::class)->handle($event, $path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
