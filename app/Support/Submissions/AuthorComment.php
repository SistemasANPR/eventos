<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Models\SubmissionStatusChange;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Comentario opcional de quien decide para el autor (plan 012, H-012-5, CA-012-27 a 29). Es texto plano:
 * se valida la longitud (error de validación bajo la clave `author_comment`) y se limpia de etiquetas
 * antes de guardarlo, así que ni el correo ni la pantalla pueden interpretarlo como HTML. Los espacios
 * sobrantes se recortan y un comentario vacío equivale a no haber puesto ninguno (`null`).
 */
final class AuthorComment
{
    /**
     * @throws ValidationException si pasa de {@see SubmissionStatusChange::COMMENT_MAX} caracteres
     */
    public static function clean(?string $comment, string $field = 'author_comment'): ?string
    {
        Validator::make(
            [$field => $comment],
            [$field => ['nullable', 'string', 'max:'.SubmissionStatusChange::COMMENT_MAX]],
            ["{$field}.max" => __('submission_decisions.author_comment.max', ['max' => SubmissionStatusChange::COMMENT_MAX])],
        )->validate();

        if ($comment === null) {
            return null;
        }

        // El contenido de <script> y <style> no es texto del autor: se descarta entero, no solo las etiquetas.
        $withoutBlocks = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $comment) ?? $comment;
        $clean = trim(strip_tags($withoutBlocks));

        return $clean === '' ? null : $clean;
    }
}
