<?php

declare(strict_types=1);

namespace App\Support\Sponsors;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Guardado y limpieza del logotipo de un patrocinador para `CreateSponsor` y `UpdateSponsor` (plan 030, CA-030-5). */
trait StoresSponsorLogo
{
    /**
     * Guarda el logotipo por su contenido. El error de `StoreProgramImage` (campo `file`) se informa en el campo `logo`.
     *
     * @throws ValidationException
     */
    private function storeLogo(UploadedFile $logo, Event $event): string
    {
        try {
            return app(StoreProgramImage::class)->handle($logo, $event, ProgramImageType::SponsorLogo);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['logo' => $exception->errors()['file'] ?? $exception->getMessage()]);
        }
    }

    /** Borra un archivo que ya no se usa. Un fallo al borrar se registra y no deshace lo guardado. */
    private function discardLogo(Event $event, string $path): void
    {
        try {
            app(DeleteProgramImage::class)->handle($event, $path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
