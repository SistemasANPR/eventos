<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

/**
 * Sobre de las rutas JSON del proyecto (app/CLAUDE.md §6):
 * `{ success: true, data }` y `{ success: false, error: { code, message, field? } }`.
 */
final class ApiEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function success(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status, $headers);
    }

    /** @param  array<string, string>  $headers */
    public static function error(string $code, string $message, int $status, ?string $field = null, array $headers = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message] + ($field === null ? [] : ['field' => $field]);

        return response()->json(['success' => false, 'error' => $error], $status, $headers);
    }
}
