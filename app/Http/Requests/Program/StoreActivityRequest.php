<?php

declare(strict_types=1);

namespace App\Http\Requests\Program;

use App\Models\ActivityCategory;
use App\Models\Event;
use App\Models\EventActivity;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una actividad dada de alta o editada a mano (plan 021, DA-4, DA-12, CA-021-4),
 * reutilizadas por las Actions y por Filament. Nombre, fecha de inicio, fecha de fin, hora de inicio
 * y hora de fin son obligatorios y el fin no es anterior al inicio; icono, imagen, descripciones y
 * categorías son opcionales. Fecha y hora son locales del evento (sin zona). Las categorías deben
 * ser del mismo evento (la consulta pasa por el filtro de organización del modelo). El contenido de
 * icono e imagen lo valida `StoreProgramImage`; aquí solo se exige que sean archivos.
 */
class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [EventActivity::class, $event]);
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
            'title' => ['required', 'string', 'max:200'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['required', 'date_format:H:i,H:i:s'],
            // Solo el mismo día se compara la hora: terminar «al día siguiente a las 02:00» es válido.
            'ends_at' => [
                'required',
                'date_format:H:i,H:i:s',
                Rule::when(
                    fn ($input): bool => $input->get('starts_on') === $input->get('ends_on'),
                    ['after_or_equal:starts_at'],
                ),
            ],
            'summary' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:50000'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['string', self::categoryOfEvent($event)],
            'is_published' => ['sometimes', 'boolean'],
            'icon' => ['nullable', 'file'],
            'image' => ['nullable', 'file'],
            'remove_icon' => ['sometimes', 'boolean'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    private static function categoryOfEvent(Event $event): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($event): void {
            $exists = is_string($value)
                && ActivityCategory::query()->where('event_id', $event->id)->whereKey($value)->exists();

            if (! $exists) {
                $fail('program.activities.category_invalid')->translate();
            }
        };
    }
}
