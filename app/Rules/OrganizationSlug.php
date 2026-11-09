<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Organization;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Formato y reservados del `slug` de una organización. La unicidad la garantiza
 * el índice único de la base; las Actions que crean organizaciones la comprueban
 * además con `unique` para dar un error de validación legible.
 */
final class OrganizationSlug implements ValidationRule
{
    /** El modificador `D` evita que `$` acepte un salto de línea final. */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public const MIN_LENGTH = 3;

    public const MAX_LENGTH = 63;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->hasValidFormat($value)) {
            $fail('organizations.slug.invalid')->translate();

            return;
        }

        if (in_array($value, Organization::RESERVED_SLUGS, true)) {
            $fail('organizations.slug.reserved')->translate();
        }
    }

    private function hasValidFormat(string $value): bool
    {
        $length = strlen($value);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $value) === 1;
    }
}
