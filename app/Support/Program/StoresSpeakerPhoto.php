<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Guardado y limpieza de la fotografía de un ponente para `CreateSpeaker` y `UpdateSpeaker` (plan 021, CA-021-3). */
trait StoresSpeakerPhoto
{
    /**
     * Guarda la fotografía por su contenido. El error de `StoreProgramImage` (campo `file`) se
     * informa en el campo `photo` del formulario.
     *
     * @throws ValidationException
     */
    private function storePhoto(UploadedFile $photo, Event $event): string
    {
        try {
            return app(StoreProgramImage::class)->handle($photo, $event, ProgramImageType::Photo);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['photo' => $exception->errors()['file'] ?? $exception->getMessage()]);
        }
    }

    /** Borra un archivo que ya no se usa. Un fallo al borrar se registra y no deshace lo guardado. */
    private function discardPhoto(Event $event, string $path): void
    {
        try {
            app(DeleteProgramImage::class)->handle($event, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
