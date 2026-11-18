<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Enums\ProgramCategoryKind;
use App\Models\Event;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de una categoría de ponentes o de actividades (plan 021, DA-7, CA-021-6), reutilizadas por
 * las Actions y por Filament: el nombre es obligatorio, de hasta 100 caracteres y único en el evento
 * dentro de su catálogo, sin distinguir mayúsculas. Las comprobaciones corren en la organización del
 * evento (la consulta pasa por el filtro de organización del modelo).
 */
class StoreProgramCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');
        $kind = ProgramCategoryKind::tryFrom((string) $this->route('kind'));

        return $event instanceof Event && $kind !== null && Gate::allows('create', [$kind->modelClass(), $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');
        $kind = ProgramCategoryKind::tryFrom((string) $this->route('kind'));

        return $event instanceof Event && $kind !== null ? self::rulesFor($event, $kind) : [];
    }

    /**
     * @param  string|null  $ignoreId  categoría que se edita: no choca consigo misma
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, ProgramCategoryKind $kind, ?string $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', self::uniqueInEvent($event, $kind, $ignoreId)],
        ];
    }

    private static function uniqueInEvent(Event $event, ProgramCategoryKind $kind, ?string $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event, $kind, $ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            // `lower()` en ambos lados: la misma función que el índice único de la base.
            $taken = $kind->modelClass()::query()
                ->where('event_id', $event->id)
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail('program.category_name_taken')->translate();
            }
        };
    }
}
