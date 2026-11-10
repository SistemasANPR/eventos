<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de rol de un integrante (plan 003, CA-003-30): solo los tres roles de
 * la organización; `platform` y cualquier otro valor se rechazan. La
 * autorización la decide la Action `ChangeMemberRole` con la Policy.
 */
final class ChangeMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
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
            'role' => ['required', Rule::enum(OrganizationRole::class)],
        ];
    }
}
