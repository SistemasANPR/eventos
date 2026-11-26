<?php

declare(strict_types=1);

namespace App\Support\Extras;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Datos adicionales» de un hospedaje o de un patrocinador (plan 030, DA-7): hasta `MAX` datos por registro, cada uno con
 * título libre y tipo texto o foto. Se guardan como lista JSON `{id, label, type, value}` (texto) o `{id, label, type,
 * path}` (foto; ruta del disco `media`, nunca una URL). La lista que llega REEMPLAZA a la guardada: un dato con el `id` de
 * uno existente lo conserva (y su foto si no se sube otra); los que ya no vienen se quitan y sus archivos se borran. Las
 * Actions de alta, edición y baja de hospedajes y patrocinadores comparten esta clase.
 */
final class ExtraFields
{
    /** Datos adicionales que admite un registro. */
    public const MAX = 10;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_IMAGE];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'extra_fields' => ['nullable', 'array', 'max:'.self::MAX],
            'extra_fields.*' => ['array'],
            'extra_fields.*.id' => ['nullable', 'string', 'uuid'],
            'extra_fields.*.label' => ['required', 'string', 'max:120'],
            'extra_fields.*.type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'extra_fields.*.value' => ['nullable', 'string', 'max:500'],
            'extra_fields.*.image' => ['nullable', 'file'],
        ];
    }

    /**
     * Valida la lista que llega y devuelve la que se guardará. Guarda las fotos nuevas (anota su ruta en `$stored` para
     * que, si algo falla después, quien llama borre los huérfanos) y anota en `$removed` las rutas que dejan de usarse
     * (se borran DESPUÉS de confirmar la transacción).
     *
     * @param  array<int|string, mixed>|null  $incoming
     * @param  array<int, mixed>|null  $current  lo que hoy tiene guardado el registro
     * @param  list<string>  $stored  (por referencia) rutas guardadas ahora
     * @param  list<string>  $removed  (por referencia) rutas que quedan sin uso
     * @return list<array<string, string>>
     *
     * @throws ValidationException
     */
    public static function resolve(Event $event, ProgramImageType $imageType, ?array $incoming, ?array $current, array &$stored, array &$removed): array
    {
        $items = [];

        foreach (array_values($incoming ?? []) as $item) {
            if (is_array($item)) {
                foreach (['label', 'value'] as $key) {
                    if (is_string($item[$key] ?? null)) {
                        $item[$key] = trim($item[$key]);
                    }
                }
            }

            $items[] = $item;
        }

        Validator::make(['extra_fields' => $items], self::rules(), [], [
            'extra_fields' => __('extra_fields.section'),
            'extra_fields.*.label' => __('extra_fields.label'),
            'extra_fields.*.type' => __('extra_fields.type'),
            'extra_fields.*.value' => __('extra_fields.value'),
            'extra_fields.*.image' => __('extra_fields.image'),
        ])->validate();

        $currentById = [];

        foreach ($current ?? [] as $existing) {
            if (is_array($existing) && is_string($existing['id'] ?? null)) {
                $currentById[$existing['id']] = $existing;
            }
        }

        $result = [];
        $kept = [];

        foreach ($items as $item) {
            $id = is_string($item['id'] ?? null) ? $item['id'] : null;
            // Un mismo identificador repetido solo cuenta una vez: la segunda copia es un dato nuevo.
            $existing = $id !== null && isset($currentById[$id]) && ! in_array($id, $kept, true) ? $currentById[$id] : null;
            $entryId = $existing !== null ? (string) $id : (string) Str::uuid();
            $kept[] = $entryId;

            $label = (string) $item['label'];
            $existingPath = $existing !== null && ($existing['type'] ?? null) === self::TYPE_IMAGE && is_string($existing['path'] ?? null)
                ? $existing['path']
                : null;

            if ($item['type'] === self::TYPE_TEXT) {
                $value = (string) ($item['value'] ?? '');

                if ($value === '') {
                    throw ValidationException::withMessages(['extra_fields' => __('extra_fields.errors.value_required', ['label' => $label])]);
                }

                if ($existingPath !== null) {
                    $removed[] = $existingPath;
                }

                $result[] = ['id' => $entryId, 'label' => $label, 'type' => self::TYPE_TEXT, 'value' => $value];

                continue;
            }

            $file = $item['image'] ?? null;

            if ($file instanceof UploadedFile) {
                try {
                    $path = app(StoreProgramImage::class)->handle($file, $event, $imageType);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['extra_fields' => $exception->errors()['file'] ?? $exception->getMessage()]);
                }

                $stored[] = $path;

                if ($existingPath !== null) {
                    $removed[] = $existingPath;
                }
            } elseif ($existingPath !== null) {
                $path = $existingPath;
            } else {
                throw ValidationException::withMessages(['extra_fields' => __('extra_fields.errors.image_required', ['label' => $label])]);
            }

            $result[] = ['id' => $entryId, 'label' => $label, 'type' => self::TYPE_IMAGE, 'path' => $path];
        }

        // Los datos que ya no vienen se quitan, y con ellos sus fotos.
        foreach ($currentById as $id => $existing) {
            if (! in_array($id, $kept, true) && ($existing['type'] ?? null) === self::TYPE_IMAGE && is_string($existing['path'] ?? null)) {
                $removed[] = $existing['path'];
            }
        }

        return $result;
    }

    /**
     * Rutas de las fotos de una lista de datos adicionales (para borrarlas al eliminar el registro).
     *
     * @param  array<int, mixed>|null  $items
     * @return list<string>
     */
    public static function paths(?array $items): array
    {
        $paths = [];

        foreach ($items ?? [] as $item) {
            if (is_array($item) && ($item['type'] ?? null) === self::TYPE_IMAGE && is_string($item['path'] ?? null)) {
                $paths[] = $item['path'];
            }
        }

        return $paths;
    }

    /**
     * Borra del disco los archivos que ya no se usan. Un fallo al borrar se registra y no deshace lo guardado.
     *
     * @param  list<string>  $paths
     */
    public static function discard(Event $event, array $paths): void
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
