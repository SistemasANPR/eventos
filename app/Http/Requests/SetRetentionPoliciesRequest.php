<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RetentionCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Plazos de retención de una organización (plan 014, CA-014-35): por categoría, un número entero de días o
 * nada (sin plazo: esa categoría no se toca). Un plazo de cero borraría todo en la primera ejecución, así que
 * el mínimo es un día. Las claves son los valores de `RetentionCategory`. La autorización la decide la Action
 * con la Policy.
 */
final class SetRetentionPoliciesRequest extends FormRequest
{
    public const MIN_DAYS = 1;

    public const MAX_DAYS = 36500;

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
        $rules = [];

        foreach (RetentionCategory::cases() as $category) {
            $rules[$category->value] = ['nullable', 'integer', 'between:'.self::MIN_DAYS.','.self::MAX_DAYS];
        }

        return $rules;
    }
}
