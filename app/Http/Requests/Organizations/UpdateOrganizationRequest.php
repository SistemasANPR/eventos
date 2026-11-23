<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Nombre de la organización al editar sus datos (plan 028, CA-028-2). */
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
        return self::rulesFor();
    }

    /** @return array<string, mixed> */
    public static function rulesFor(): array
    {
        return ['name' => ['required', 'string', 'min:'.self::NAME_MIN, 'max:'.self::NAME_MAX]];
    }
}
