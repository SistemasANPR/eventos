<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirar la propuesta (plan 006, H-006-5). El POST no lleva datos: la propuesta sale de la sesión del autor,
 * nunca del cuerpo ni de la URL (CA-006-38). La autorización es la sesión (`AuthenticateAuthor`) y la
 * pertenencia, `ResolveAuthorSubmission`.
 */
final class WithdrawSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }
}
