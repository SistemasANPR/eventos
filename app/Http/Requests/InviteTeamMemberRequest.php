<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un integrante del equipo (plan 003, CA-003-21, -23 y -30).
 *
 * Las reglas viven en un método estático para que las use también la Action
 * `InviteTeamMember` y los campos de Filament, que no tienen petición HTTP.
 * El rol solo puede ser uno de `OrganizationRole`: `platform` y cualquier otro
 * valor quedan fuera, y la marca `is_platform_admin` se prohíbe de forma explícita.
 */
final class InviteTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', User::class);
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
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'is_platform_admin' => ['prohibited'],
        ];
    }
}
