<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use App\Models\EventActivity;
use App\Support\Html\RichTextSanitizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Piezas que comparten el alta y la edición de una actividad (plan 021): hora normalizada, guardado
 * de icono e imagen con el error en su propio campo, y vínculo con las categorías.
 */
final class ActivityWrites
{
    /**
     * Quita los espacios sobrantes del nombre y de la descripción corta; una descripción corta vacía es nula.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function trimmed(array $data): array
    {
        if (is_string($data['title'] ?? null)) {
            $data['title'] = trim($data['title']);
        }

        if (is_string($data['summary'] ?? null)) {
            $data['summary'] = trim($data['summary']) ?: null;
        }

        return $data;
    }

    /** Descripción enriquecida saneada (DA-16); sin texto visible, nula. */
    public static function description(mixed $value): ?string
    {
        $clean = is_string($value) ? RichTextSanitizer::clean($value) : '';

        return $clean === '' ? null : $clean;
    }

    /** Hora `HH:MM` o `HH:MM:SS` como la guarda la base (`time`). */
    public static function time(string $value): string
    {
        return strlen($value) === 5 ? "{$value}:00" : $value;
    }

    /**
     * Guarda el archivo y devuelve su ruta. El rechazo de `StoreProgramImage` (campo `file`) se
     * reporta en el campo del formulario (`icon` o `image`).
     *
     * @throws ValidationException
     */
    public static function storeImage(UploadedFile $file, Event $event, ProgramImageType $type, string $field): string
    {
        try {
            return app(StoreProgramImage::class)->handle($file, $event, $type);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$field => $exception->errors()['file'] ?? []]);
        }
    }

    /**
     * Deja exactamente estas categorías (del mismo evento) en la actividad.
     *
     * @param  list<string>  $categoryIds
     */
    public static function syncCategories(EventActivity $activity, array $categoryIds): void
    {
        $pivot = ['organization_id' => $activity->organization_id, 'event_id' => $activity->event_id];

        $activity->categories()->sync(array_fill_keys($categoryIds, $pivot));
    }
}
