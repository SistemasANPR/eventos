<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Models\Event;
use App\Models\Room;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de una sala del evento (plan 031, CA-031-5), reutilizadas por las Actions y por Filament: el
 * nombre es obligatorio, de hasta 100 caracteres y único en el evento sin distinguir mayúsculas. Las
 * comprobaciones corren en la organización del evento (la consulta pasa por el filtro de organización
 * del modelo).
 */
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [Room::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }

    /**
     * @param  string|null  $ignoreId  sala que se edita: no choca consigo misma
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, ?string $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:100', self::uniqueInEvent($event, $ignoreId)],
        ];
    }

    private static function uniqueInEvent(Event $event, ?string $ignoreId): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event, $ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            // `lower()` en ambos lados: la misma función que el índice único de la base.
            $taken = Room::query()
                ->where('event_id', $event->id)
                ->whereRaw('lower(name) = lower(?)', [$value])
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();

            if ($taken) {
                $fail('program.rooms.name_taken')->translate();
            }
        };
    }
}
