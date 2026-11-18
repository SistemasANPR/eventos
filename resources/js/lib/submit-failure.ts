import type { SubmitFailure, SubmitFailureCode } from '@/types/submission';

const SERVER_CODES: readonly SubmitFailureCode[] = [
    'CALL_NOT_OPEN',
    'CALL_NOT_READY',
    'FORM_OUTDATED',
    'IDEMPOTENCY_PAYLOAD_MISMATCH',
    'TOO_MANY_REQUESTS',
    'TURNSTILE_UNAVAILABLE',
];

/**
 * Interpreta una respuesta HTTP de error del envío (`{code, message}` más `Retry-After`). Lo que
 * no reconoce es un fallo genérico: la página siempre muestra algo y conserva lo escrito.
 */
export function toSubmitFailure(response: { status: number; headers: Record<string, string>; data: string | Record<string, unknown> }): SubmitFailure {
    const body = typeof response.data === 'object' ? response.data : {};
    const known = SERVER_CODES.find((code) => code === body.code);
    const retryAfter = Number(response.headers['retry-after']);

    return {
        code: known ?? (response.status === 429 ? 'TOO_MANY_REQUESTS' : 'GENERIC'),
        message: typeof body.message === 'string' && body.message !== '' ? body.message : null,
        retryAfterSeconds: Number.isFinite(retryAfter) && retryAfter > 0 ? retryAfter : null,
    };
}
