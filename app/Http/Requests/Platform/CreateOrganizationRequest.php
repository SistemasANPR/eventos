<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Models\Organization;
use App\Rules\OrganizationSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Reglas del alta de una organización con su primer administrador (plan 016,
 * CA-016-11 y -12), compartidas por la Action, el comando y el panel.
 */
class CreateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Organization::class);
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
            'slug' => ['required', new OrganizationSlug],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
        ];
    }
}
