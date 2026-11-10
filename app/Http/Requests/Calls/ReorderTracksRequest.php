<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Models\Event;
use App\Models\Track;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas para reordenar los ejes de un evento (plan 004, CA-004-12): la lista
 * trae TODOS los ejes del evento, cada uno una sola vez. Un identificador ajeno
 * (otro evento u otra organización) no está en el conjunto y se rechaza.
 */
class ReorderTracksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('reorder', [Track::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }

    /** @return array<string, mixed> */
    public static function rulesFor(Event $event): array
    {
        return [
            'order' => ['required', 'array', self::exactlyTheTracksOf($event)],
            'order.*' => ['string'],
        ];
    }

    private static function exactlyTheTracksOf(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            $expected = Track::query()->where('event_id', $event->id)->pluck('id')->all();
            $received = is_array($value) ? array_values($value) : [];

            sort($expected);
            sort($received);

            if ($expected !== $received) {
                $fail('calls.track_order_invalid')->translate();
            }
        };
    }
}
