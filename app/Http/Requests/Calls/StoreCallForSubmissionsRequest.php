<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\CallForSubmissions;
use App\Models\Event;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de una convocatoria (plan 004, H-004-1), reutilizadas por las Actions y
 * por Filament. El identificador de la URL no se captura: lo genera `CallSlug`. Las fechas llegan como hora LOCAL del evento (`Y-m-d H:i`) y se
 * convierten a UTC al guardar. El idioma de la convocatoria debe ser uno de los
 * del evento.
 */
class StoreCallForSubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [CallForSubmissions::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $event = $this->route('event');

        return $event instanceof Event ? self::rulesFor($event) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, ?string $currentTypeId = null): array
    {
        return [
            // Solo un tipo ACTIVO de este evento; al editar, también el que la convocatoria ya tiene (CA-004-32).
            'submission_type_id' => [
                'required', 'uuid',
                Rule::exists('submission_types', 'id')
                    ->where('organization_id', $event->organization_id)
                    ->where('event_id', $event->id)
                    ->where(fn ($query) => $query->where(fn ($inner) => $inner->whereNull('archived_at')->orWhere('id', $currentTypeId))),
            ],
            'title' => ['required', 'array:'.implode(',', StoreEventRequest::LOCALES), self::atLeastOneText()],
            'title.*' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array:'.implode(',', StoreEventRequest::LOCALES)],
            'description.*' => ['nullable', 'string', 'max:5000'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', 'distinct', Rule::in($event->locales)],
            'opens_at' => ['required', 'date_format:Y-m-d H:i'],
            'closes_at' => ['required', 'date_format:Y-m-d H:i', 'after:opens_at'],
            'results_at' => ['nullable', 'date_format:Y-m-d H:i', 'after_or_equal:closes_at'],
        ];
    }

    private static function atLeastOneText(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $hasText = is_array($value) && array_filter($value, static fn (mixed $text): bool => is_string($text) && trim($text) !== '') !== [];

            if (! $hasText) {
                $fail('calls.title_required')->translate();
            }
        };
    }
}
