<?php

declare(strict_types=1);

namespace App\Http\Requests\Privacy;

use App\Http\Requests\Events\StoreEventRequest;
use App\Models\Event;
use App\Models\PrivacyNotice;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas del texto de un aviso de privacidad (plan 003, H-003-2), reutilizadas
 * por las Actions y por Filament. El texto llega por idioma y se sanitiza en la
 * Action antes de guardarse; aquí solo se valida su forma.
 */
class StorePrivacyNoticeRequest extends FormRequest
{
    /** Longitud máxima del texto de un idioma (HTML ya sanitizado). */
    public const MAX_LENGTH = 50_000;

    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('create', [PrivacyNotice::class, $event]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /** @return array<string, mixed> */
    public static function rulesFor(): array
    {
        return [
            'body' => ['required', 'array:'.implode(',', StoreEventRequest::LOCALES), self::atLeastOneText()],
            'body.*' => ['nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /** El borrador puede faltar en algunos idiomas (se exige el idioma por defecto al publicar), no en todos. */
    private static function atLeastOneText(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            $hasText = is_array($value) && array_filter($value, static fn (mixed $text): bool => is_string($text) && trim($text) !== '') !== [];

            if (! $hasText) {
                $fail('privacy.body_required')->translate();
            }
        };
    }
}
