<?php

declare(strict_types=1);

namespace App\Http\Requests\Events;

use App\Enums\EventType;
use App\Models\Event;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reglas de un evento (plan 003, H-003-1), reutilizadas por las Actions y por
 * Filament: la validación vive en un solo lugar y el servidor es el único que
 * decide. Las fechas llegan como hora LOCAL del evento (`Y-m-d H:i`) y se
 * convierten a UTC al guardar.
 */
class StoreEventRequest extends FormRequest
{
    /** Idiomas permitidos (plan 003, DA-1). */
    public const LOCALES = ['es', 'en', 'pt'];

    /** Monedas permitidas; en R0 solo se usa MXN (ADR-0005, DA-1). */
    public const CURRENCIES = ['MXN', 'USD'];

    public function authorize(): bool
    {
        return Gate::allows('create', Event::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var list<string> $locales */
        $locales = (array) $this->input('locales', []);

        return self::rulesFor(CurrentOrganization::id(), $locales);
    }

    /**
     * @param  list<string>  $locales  idiomas que declara el formulario (para validar el idioma por defecto)
     * @return array<string, mixed>
     */
    public static function rulesFor(string $organizationId, array $locales, ?string $ignoreEventId = null): array
    {
        return [
            'name' => ['required', 'array:'.implode(',', self::LOCALES), self::atLeastOneText()],
            'name.*' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'array:'.implode(',', self::LOCALES)],
            'description.*' => ['nullable', 'string', 'max:5000'],
            'slug' => [
                'required', 'string', 'min:3', 'max:100', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('events', 'slug')->where('organization_id', $organizationId)->ignore($ignoreEventId),
            ],
            'type' => ['required', Rule::enum(EventType::class)],
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i', 'after_or_equal:starts_at'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'venue_name' => ['nullable', 'string', 'max:255'],
            'venue_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['string', 'distinct', Rule::in(self::LOCALES)],
            'default_locale' => ['required', 'string', Rule::in($locales)],
            'currency' => ['required', 'string', Rule::in(self::CURRENCIES)],
        ];
    }

    /** El nombre puede faltar en algunos idiomas (se exige en el idioma por defecto al publicar), no en todos. */
    private static function atLeastOneText(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || array_filter($value, static fn (mixed $text): bool => is_string($text) && trim($text) !== '') === []) {
                $fail('events.name_required')->translate();
            }
        };
    }
}
