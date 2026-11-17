<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\SubmissionType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas de un tipo de propuesta (plan 004, DA-7, CA-004-30), reutilizadas por
 * las Actions y por Filament. El nombre es traducible y es obligatorio en el
 * idioma base del evento; los demás idiomas son opcionales y caen a él.
 */
class StoreSubmissionTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [SubmissionType::class, $event]);
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
            'name' => ['required', 'array:'.implode(',', StoreEventRequest::LOCALES), self::defaultLocaleText($event)],
            'name.*' => ['nullable', 'string', 'max:100'],
        ];
    }

    private static function defaultLocaleText(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            $text = is_array($value) ? ($value[$event->default_locale] ?? null) : null;

            if (! is_string($text) || trim($text) === '') {
                $fail('calls.type_name_required')->translate();
            }
        };
    }
}
