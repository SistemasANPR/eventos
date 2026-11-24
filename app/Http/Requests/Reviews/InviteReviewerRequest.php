<?php

declare(strict_types=1);

namespace App\Http\Requests\Reviews;

use App\Models\Event;
use App\Models\EventAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Invitación de un evaluador a un evento (plan 011, H-011-8, CA-011-33 y 35). Las reglas viven en un método
 * estático para que las use también la Action `InviteReviewer` y los campos de Filament, que no tienen
 * petición HTTP. Que el evento sea de la organización lo garantiza la ruta (404 si no); que la invitación
 * proceda (2FA activa, correo no desactivado) lo comprueba la Action.
 */
final class InviteReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && Gate::allows('inviteReviewer', [EventAssignment::class, $event]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /** @return array<string, list<mixed>> */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }
}
