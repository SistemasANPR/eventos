<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Models\Organization;
use App\Rules\OrganizationSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Nombre e identificador (`slug`) de la organización al editar sus datos
 * (plan 028, CA-028-2 y CA-028-7). El identificador es único entre todas las
 * organizaciones, también las eliminadas (el índice único las cuenta), salvo la propia.
 */
class UpdateOrganizationRequest extends FormRequest
{
    public const NAME_MIN = 2;

    public const NAME_MAX = 120;

    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization && Gate::allows('update', $organization);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organization = $this->route('organization');

        return self::rulesFor($organization instanceof Organization ? $organization : new Organization);
    }

    /** @return array<string, mixed> */
    public static function rulesFor(Organization $organization): array
    {
        return [
            'name' => ['required', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX],
            'slug' => ['sometimes', 'required', 'string', new OrganizationSlug, Rule::unique('organizations', 'slug')->ignore($organization->id)],
        ];
    }
}
