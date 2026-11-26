<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Enums\RoomKind;
use App\Models\Event;
use App\Models\Room;
use App\Support\Program\RoomWrites;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de un lugar del evento (plan 031, CA-031-5; plan 035, CA-035-3 a 5), reutilizadas por las Actions y por
 * Filament: el nombre es obligatorio, de hasta 100 caracteres y único en el evento sin distinguir mayúsculas; el
 * tipo (`room` o `external`), la capacidad (entero de 1 a 100000), su visibilidad pública, la dirección (hasta 255) y
 * el mapa (enlace `https` de hasta 2048) son opcionales y, si no vienen, no cambian. Las
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
            'kind' => ['sometimes', Rule::enum(RoomKind::class)],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:'.RoomWrites::MAX_CAPACITY],
            'capacity_is_public' => ['sometimes', 'boolean'],
            'address' => ['nullable', 'string', 'max:255'],
            'map_url' => ['nullable', 'string', 'max:2048', 'url:https'],
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
