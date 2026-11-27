<?php

declare(strict_types=1);

namespace App\Support\Extras;

use App\Actions\Program\DeleteProgramImage;
use App\Actions\Program\StoreProgramImage;
use App\Enums\ProgramImageType;
use App\Exceptions\Domain\PrivateUploadFailed;
use App\Models\Event;
use App\Models\ExtraFieldDefinition;
use App\Models\Lodging;
use App\Models\Organization;
use App\Models\Sponsor;
use App\Support\Files\MediaDisk;
use App\Support\Files\PrivateUploads;
use App\Support\Tenancy\CurrentOrganization;
use App\Support\Tenancy\TenantStoragePath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Respuestas a las preguntas del formulario de un hospedaje o de un patrocinador (plan 030, DA-7). Las preguntas
 * (`ExtraFieldDefinition`) se definen una vez por evento y módulo; cada registro guarda SUS respuestas en `extra_fields`
 * como mapa `{id de la pregunta: valor}`: texto, número, fecha `Y-m-d`, `true` (casilla marcada), el valor de la opción,
 * la lista de valores de las opciones, o `{"path": ...}` para una foto o un PDF (la ruta del disco `media`, nunca una URL).
 *
 * Reglas de `resolve`: solo cuentan las preguntas del evento y módulo (un identificador ajeno se ignora); una pregunta
 * que no llega conserva lo que ya tenía; un valor vacío la borra; una foto o PDF se reemplaza con un archivo nuevo o se
 * quita si su identificador viene en `$remove`; una pregunta obligatoria sin respuesta rechaza el guardado. Las Actions
 * de alta, edición y baja de hospedajes y patrocinadores comparten esta clase.
 */
final class ExtraFields
{
    /** Preguntas que admite un evento por módulo. */
    public const MAX = 10;

    public const MAX_SHORT_TEXT = 500;

    public const MAX_LONG_TEXT = 2000;

    /** Tope de una foto o un PDF (2 MB), igual que el de las demás imágenes de hospedaje y patrocinador. */
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;

    /**
     * Preguntas del evento y módulo, en su orden.
     *
     * @return list<ExtraFieldDefinition>
     */
    public static function definitions(Event $event, string $scope): array
    {
        return ExtraFieldDefinition::query()
            ->where('event_id', $event->id)
            ->where('scope', $scope)
            ->orderBy('position')
            ->get()
            ->all();
    }

    /**
     * Calcula el mapa que se guardará. Guarda los archivos nuevos (anota su ruta en `$stored` para que, si algo falla
     * después, quien llama borre los huérfanos) y anota en `$removed` las rutas que dejan de usarse (se borran DESPUÉS de
     * confirmar la transacción).
     *
     * @param  array<int|string, mixed>|null  $incoming  respuestas que llegan: id de la pregunta => valor o archivo subido
     * @param  array<int|string, mixed>|null  $remove  identificadores de preguntas de archivo cuyo archivo se quita
     * @param  array<string, mixed>|null  $current  lo que hoy tiene guardado el registro
     * @param  list<string>  $stored  (por referencia) rutas guardadas ahora
     * @param  list<string>  $removed  (por referencia) rutas que quedan sin uso
     * @return array<string, mixed>|null nulo si no queda ninguna respuesta
     *
     * @throws ValidationException
     */
    public static function resolve(Event $event, string $scope, ProgramImageType $imageType, ?array $incoming, ?array $remove, ?array $current, array &$stored, array &$removed): ?array
    {
        $result = [];
        $incoming ??= [];
        $remove = array_values(array_filter($remove ?? [], is_string(...)));

        foreach (self::definitions($event, $scope) as $definition) {
            $id = $definition->id;
            $existing = $current[$id] ?? null;

            if ($definition->isFile()) {
                $existingPath = is_array($existing) && is_string($existing['path'] ?? null) ? $existing['path'] : null;
                $file = $incoming[$id] ?? null;

                if ($file instanceof UploadedFile) {
                    $path = self::storeFile($file, $event, $definition, $imageType);
                    $stored[] = $path;

                    if ($existingPath !== null) {
                        $removed[] = $existingPath;
                    }

                    $result[$id] = ['path' => $path];
                } elseif ($existingPath !== null && in_array($id, $remove, true)) {
                    $removed[] = $existingPath;
                } elseif ($existingPath !== null) {
                    $result[$id] = ['path' => $existingPath];
                }

                if ($definition->required && ! isset($result[$id])) {
                    throw self::failure($id, 'file_required', $definition);
                }

                continue;
            }

            $value = self::normalize($definition, array_key_exists($id, $incoming) ? $incoming[$id] : $existing);

            if ($value === null && $definition->required) {
                throw self::failure($id, 'required', $definition);
            }

            if ($value !== null) {
                $result[$id] = $value;
            }
        }

        // Lo guardado para una pregunta que ya no existe en el evento no se conserva: su archivo se borra.
        foreach (self::paths($current) as $path) {
            if (! in_array($path, $removed, true) && ! in_array($path, self::paths($result), true)) {
                $removed[] = $path;
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * Rutas de los archivos de un mapa de respuestas (para borrarlos al eliminar el registro).
     *
     * @param  array<int|string, mixed>|null  $values
     * @return list<string>
     */
    public static function paths(?array $values): array
    {
        $paths = [];

        foreach ($values ?? [] as $value) {
            if (is_array($value) && is_string($value['path'] ?? null)) {
                $paths[] = $value['path'];
            }
        }

        return $paths;
    }

    /**
     * Respuestas guardadas que se muestran como valor del formulario al editar (los archivos no salen: solo se sabe si ya
     * tienen uno).
     *
     * @param  array<int|string, mixed>|null  $values
     * @return array<string, mixed>
     */
    public static function fill(?array $values): array
    {
        $filled = [];

        foreach ($values ?? [] as $id => $value) {
            if (! (is_array($value) && array_key_exists('path', $value))) {
                $filled[(string) $id] = $value;
            }
        }

        return $filled;
    }

    /** Registros del evento y módulo que tienen respuesta a esa pregunta (lo que se perdería al quitarla). */
    public static function answersCount(Event $event, string $scope, string $id): int
    {
        $count = 0;

        foreach (self::records($event, $scope) as $record) {
            if (is_array($record->extra_fields) && array_key_exists($id, $record->extra_fields)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Registros del evento y módulo que ya tienen alguna respuesta.
     *
     * @return iterable<int, Lodging|Sponsor>
     */
    public static function records(Event $event, string $scope): iterable
    {
        return $scope === ExtraFieldDefinition::SCOPE_LODGING
            ? Lodging::query()->where('event_id', $event->id)->whereNotNull('extra_fields')->get()
            : Sponsor::query()->where('event_id', $event->id)->whereNotNull('extra_fields')->get();
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

    /**
     * Valor limpio y validado de una respuesta que no es un archivo; nulo si quedó vacía.
     *
     * @return string|int|float|bool|list<string>|null
     *
     * @throws ValidationException
     */
    private static function normalize(ExtraFieldDefinition $definition, mixed $raw): string|int|float|bool|array|null
    {
        $id = $definition->id;

        switch ($definition->type) {
            case ExtraFieldDefinition::TYPE_SHORT_TEXT:
            case ExtraFieldDefinition::TYPE_LONG_TEXT:
                if ($raw !== null && ! is_string($raw)) {
                    throw self::failure($id, 'text_invalid', $definition);
                }

                $text = trim((string) $raw);
                $max = $definition->type === ExtraFieldDefinition::TYPE_SHORT_TEXT ? self::MAX_SHORT_TEXT : self::MAX_LONG_TEXT;

                if (mb_strlen($text) > $max) {
                    throw self::failure($id, 'text_too_long', $definition, ['max' => $max]);
                }

                return $text === '' ? null : $text;

            case ExtraFieldDefinition::TYPE_NUMBER:
                if ($raw === null || $raw === '') {
                    return null;
                }

                if (! is_numeric($raw)) {
                    throw self::failure($id, 'number_invalid', $definition);
                }

                $number = $raw + 0;
                $min = $definition->config['min'] ?? null;
                $max = $definition->config['max'] ?? null;

                if ((is_numeric($min) && $number < $min) || (is_numeric($max) && $number > $max)) {
                    throw self::failure($id, 'number_range', $definition, [
                        'min' => is_numeric($min) ? (string) $min : '—',
                        'max' => is_numeric($max) ? (string) $max : '—',
                    ]);
                }

                return $number;

            case ExtraFieldDefinition::TYPE_DATE:
                if ($raw === null || $raw === '') {
                    return null;
                }

                if (! is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    throw self::failure($id, 'date_invalid', $definition);
                }

                return $raw;

            case ExtraFieldDefinition::TYPE_CHECKBOX:
                // Solo se guarda la casilla marcada: sin marcar es lo mismo que sin respuesta.
                return filter_var($raw, FILTER_VALIDATE_BOOLEAN) ? true : null;

            case ExtraFieldDefinition::TYPE_SINGLE_CHOICE:
                if ($raw === null || $raw === '') {
                    return null;
                }

                if (! is_string($raw) || ! array_key_exists($raw, $definition->options())) {
                    throw self::failure($id, 'option_invalid', $definition);
                }

                return $raw;

            default: // TYPE_MULTIPLE_CHOICE
                if ($raw === null || $raw === []) {
                    return null;
                }

                $options = $definition->options();

                if (! is_array($raw)) {
                    throw self::failure($id, 'option_invalid', $definition);
                }

                foreach ($raw as $value) {
                    if (! is_string($value) || ! array_key_exists($value, $options)) {
                        throw self::failure($id, 'option_invalid', $definition);
                    }
                }

                // En el orden de las opciones, sin repetidos.
                return array_values(array_intersect(array_keys($options), $raw));
        }
    }

    /**
     * Guarda una foto o un PDF en el disco `media` y devuelve su ruta.
     *
     * @throws ValidationException
     */
    private static function storeFile(UploadedFile $file, Event $event, ExtraFieldDefinition $definition, ProgramImageType $imageType): string
    {
        try {
            return $definition->type === ExtraFieldDefinition::TYPE_PHOTO
                ? app(StoreProgramImage::class)->handle($file, $event, $imageType)
                : self::storePdf($file, $event);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(["extra_fields.{$definition->id}" => $exception->errors()['file'] ?? $exception->getMessage()]);
        }
    }

    /**
     * Guarda un PDF por su CONTENIDO (cabecera `%PDF-`, tipo real y sin código pegado), hasta 2 MB. El nombre en disco
     * lo genera el servidor.
     *
     * @throws ValidationException
     * @throws PrivateUploadFailed
     */
    private static function storePdf(UploadedFile $file, Event $event): string
    {
        $organization = Organization::query()->findOrFail($event->organization_id);

        return CurrentOrganization::run($organization, function () use ($file, $event): string {
            $real = $file->getRealPath();

            if (! $file->isValid() || $real === false) {
                throw self::fileRejected('files.upload_failed');
            }

            $size = (int) filesize($real);

            if ($size === 0) {
                throw self::fileRejected('files.empty');
            }

            if ($size > self::MAX_FILE_BYTES) {
                throw self::fileRejected('files.too_large', ['max' => '2 MB']);
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo === false ? false : finfo_file($finfo, $real);

            if ($mime !== 'application/pdf' || file_get_contents($real, false, null, 0, 5) !== '%PDF-') {
                throw self::fileRejected('form_questions.errors.pdf_invalid');
            }

            if (PrivateUploads::isPolyglot($real)) {
                throw self::fileRejected('files.suspicious_content');
            }

            $path = TenantStoragePath::for("events/{$event->id}/media/form_file/".Str::uuid().'.pdf');
            $stored = MediaDisk::get()->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);

            if ($stored === false) {
                throw PrivateUploadFailed::onDisk(MediaDisk::name());
            }

            return $path;
        });
    }

    /** @param  array<string, string>  $replace */
    private static function fileRejected(string $key, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(['file' => __($key, $replace)]);
    }

    /** @param  array<string, string|int>  $replace */
    private static function failure(string $id, string $error, ExtraFieldDefinition $definition, array $replace = []): ValidationException
    {
        return ValidationException::withMessages(["extra_fields.{$id}" => __("form_questions.errors.{$error}", ['label' => $definition->label, ...$replace])]);
    }
}
