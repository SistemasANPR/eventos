<?php

declare(strict_types=1);

namespace App\Http\Requests\Calls;

use App\Models\CallForSubmissions;
use App\Models\Event;
use App\Support\Events\EventAttributes;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * Datos opcionales de un cambio de estado de la convocatoria (plan 004, H-004-4):
 * al reabrir, la nueva fecha de cierre en hora LOCAL del evento (`Y-m-d H:i`).
 * Debe ser futura y posterior a la apertura. Reutilizado por la Action y por Filament.
 */
class ChangeCallStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $call = $this->route('call');

        return $call instanceof CallForSubmissions && Gate::allows('changeStatus', $call);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $call = $this->route('call');
        $event = $this->route('event');

        return $call instanceof CallForSubmissions && $event instanceof Event ? self::rulesFor($event, $call) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesFor(Event $event, CallForSubmissions $call): array
    {
        return [
            'closes_at' => [
                'bail', 'nullable', 'date_format:Y-m-d H:i',
                static function (string $attribute, mixed $value, Closure $fail) use ($event, $call): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $closes = EventAttributes::toUtc($value, $event->timezone);

                    if (! $closes->gt(Date::now())) {
                        $fail('calls.reopen_close_in_the_past')->translate();
                    } elseif (! $closes->gt($call->opens_at)) {
                        $fail('calls.reopen_close_before_opening')->translate();
                    }
                },
            ],
        ];
    }
}
