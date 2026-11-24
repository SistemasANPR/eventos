import { countdownText, ctaLabel, formatLandingDate, formatLandingTime, landingStatus, statusLabel } from '@/lib/call-landing-view';

const OPENS = '2026-11-24T06:00:00Z';
const CLOSES = '2027-01-30T23:00:00Z';
const ZONE = 'America/Mexico_City';

describe('call landing view', () => {
    it('formats the dates and the closing time in the event time zone', () => {
        expect(formatLandingDate(CLOSES, ZONE)).toBe('30 de enero de 2027');
        expect(formatLandingTime(CLOSES, ZONE, 'CDMX')).toBe('a las 17:00 h (CDMX)');
    });

    it('counts the days left until the call closes', () => {
        expect(countdownText('open', OPENS, CLOSES, ZONE, new Date('2026-11-24T12:00:00Z'))).toBe('cierra en 68 días');
        expect(countdownText('open', OPENS, CLOSES, ZONE, new Date('2027-01-30T10:00:00Z'))).toBe('cierra en menos de 24 horas');
    });

    it('explains the other states instead of counting', () => {
        expect(countdownText('scheduled', OPENS, CLOSES, ZONE)).toBe('abre el 24 de noviembre de 2026');
        expect(countdownText('paused', OPENS, CLOSES, ZONE)).toBe('recepción en pausa');
        expect(countdownText('closed', OPENS, CLOSES, ZONE)).toBe('ya no recibe propuestas');
    });

    it('maps the call state to the badge, its label and the button text', () => {
        expect(landingStatus('open')).toBe('abierta');
        expect(landingStatus('scheduled')).toBe('proxima');
        expect(landingStatus('paused')).toBe('cerrada');
        expect(statusLabel('paused')).toBe('En pausa');
        expect(ctaLabel('open')).toBe('Enviar propuesta');
        expect(ctaLabel('scheduled')).toBe('Abre pronto');
        expect(ctaLabel('closed')).toBe('Convocatoria cerrada');
    });
});
