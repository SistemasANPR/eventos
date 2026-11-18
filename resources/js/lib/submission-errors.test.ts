import { hasErrors, mapServerErrors } from '@/lib/submission-errors';

describe('mapServerErrors', () => {
    it('CA-006-56: maps fixed block, participant and answer errors to the keys of each field', () => {
        const mapped = mapServerErrors({
            title: 'Falta el título',
            track_id: 'Eje inválido',
            'participants.1.email': 'Correo repetido',
            'answers.summary': 'Obligatorio',
            'answers.topics.0': 'Opción inválida',
        });

        expect(mapped.fields).toEqual({
            's.title': 'Falta el título',
            's.track_id': 'Eje inválido',
            's.participants.1.email': 'Correo repetido',
            summary: 'Obligatorio',
            topics: 'Opción inválida',
        });
        expect(mapped.general).toEqual([]);
    });

    it('routes consent, third parties, participants and turnstile errors to their own place', () => {
        const mapped = mapServerErrors({
            consent: 'Acepta el aviso',
            privacy_notice_version_id: 'El aviso cambió',
            third_parties_authorized: 'Declara la autorización',
            participants: 'Indica un principal',
            turnstile_token: 'Verificación inválida',
        });

        expect(mapped.consent).toBe('Acepta el aviso');
        expect(mapped.thirdParties).toBe('Declara la autorización');
        expect(mapped.participants).toBe('Indica un principal');
        expect(mapped.turnstile).toBe('Verificación inválida');
        expect(mapped.fields).toEqual({});
    });

    it('keeps errors without a visible field as general errors', () => {
        const mapped = mapServerErrors({ idempotency_key: 'Sesión inválida', answers: 'Respuestas demasiado grandes' });

        expect(mapped.general).toEqual(['Sesión inválida', 'Respuestas demasiado grandes']);
        expect(hasErrors(mapped)).toBe(true);
    });

    it('reports no errors for an empty bag', () => {
        expect(hasErrors(mapServerErrors({}))).toBe(false);
    });
});
