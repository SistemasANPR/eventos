<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\Track;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de un eje temático (plan 004, H-004-2, CA-004-13), reutilizadas por las
 * Actions y por Filament. El nombre es traducible y obligatorio en el idioma
 * base del evento; los demás idiomas y la descripción son opcionales y caen al
 * idioma base al mostrarse.
 */
class StoreTrackRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [Track::class, $event]);
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
        $locales = 'array:'.implode(',', StoreEventRequest::LOCALES);

        return [
            'name' => ['required', $locales, self::defaultLocaleText($event)],
            'name.*' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', $locales],
            'description.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private static function defaultLocaleText(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            $text = is_array($value) ? ($value[$event->default_locale] ?? null) : null;

            if (! is_string($text) || trim($text) === '') {
                $fail('calls.track_name_required')->translate();
            }
        };
    }
}
