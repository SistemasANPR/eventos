import { toSubmitFailure } from '@/lib/submit-failure';

describe('toSubmitFailure', () => {
    it('reads the code, the message and Retry-After of a server rejection', () => {
        expect(toSubmitFailure({ status: 429, headers: { 'retry-after': '120' }, data: { code: 'TOO_MANY_REQUESTS', message: 'Espera' } })).toEqual({
            code: 'TOO_MANY_REQUESTS',
            message: 'Espera',
            retryAfterSeconds: 120,
        });
    });

    it('recognizes the conflict codes of the submission', () => {
        for (const code of ['CALL_NOT_OPEN', 'FORM_OUTDATED', 'IDEMPOTENCY_PAYLOAD_MISMATCH', 'TURNSTILE_UNAVAILABLE', 'CALL_NOT_READY']) {
            expect(toSubmitFailure({ status: 409, headers: {}, data: { code } }).code).toBe(code);
        }
    });

    it('falls back to a generic failure for anything it does not know, including an HTML error page', () => {
        expect(toSubmitFailure({ status: 500, headers: {}, data: '<html>error</html>' })).toEqual({ code: 'GENERIC', message: null, retryAfterSeconds: null });
        expect(toSubmitFailure({ status: 429, headers: {}, data: '<html>429</html>' }).code).toBe('TOO_MANY_REQUESTS');
    });
});
