<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Motivo obligatorio al suspender una organización (plan 016, CA-016-13 y -14). */
class SuspendOrganizationRequest extends FormRequest
{
    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization && Gate::allows('suspend', $organization);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::rulesFor();
    }

    /** @return array<string, mixed> */
    public static function rulesFor(): array
    {
        return ['reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX]];
    }
}
