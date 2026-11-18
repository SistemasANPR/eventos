import { formatEventDates, formatEventInstant, formatPublishedAt } from '@/lib/date-format';

const STARTS_AT = '2027-03-10T15:00:00Z';
const ENDS_AT = '2027-03-12T23:00:00Z';

describe('formatEventDates', () => {
    it('shows the times in the time zone of the event, not in UTC', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '12h');

        expect(text).toMatch(/\b9:00\b/);
        expect(text).not.toMatch(/\b15:00\b/);
    });

    it('formats in the language of the page', () => {
        const spanish = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '12h');
        const english = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'en', '12h');

        expect(spanish).toContain('marzo');
        expect(english).toContain('March');
    });

    it('moves the day when the zone crosses midnight', () => {
        const text = formatEventDates('2027-03-10T02:00:00Z', '2027-03-10T05:00:00Z', 'America/Mexico_City', 'en', '12h');

        expect(text).toContain('March 9');
    });
});

describe('time format of the event', () => {
    it('CA-022-12: shows 12 hours with a day period when the event uses 12 hours', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '12h');

        expect(text).toMatch(/\b9:00\s?a\.?\s?m\.?/i);
        expect(text).toMatch(/\b5:00\s?p\.?\s?m\.?/i);
    });

    it('CA-022-12: shows 24 hours without a day period when the event uses 24 hours', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '24h');

        // ICU escribe la hora de 24 h en español sin cero inicial («9:00»), a diferencia del panel («09:00 h»).
        expect(text).toMatch(/\b9:00\b/);
        expect(text).toMatch(/\b17:00\b/);
        expect(text).not.toMatch(/\b[ap]\.?\s?m\./i);
    });

    it('CA-022-12: follows the option in English too, and never writes midnight as 24:00', () => {
        expect(formatEventInstant('2027-03-10T15:00:00Z', 'America/Mexico_City', 'en', '12h')).toMatch(/9:00\s?AM/);
        expect(formatEventInstant('2027-03-10T15:00:00Z', 'America/Mexico_City', 'en', '24h')).toMatch(/\b09:00\b/);
        expect(formatEventInstant('2027-03-10T06:00:00Z', 'America/Mexico_City', 'en', '24h')).not.toMatch(/\b24:00\b/);
    });
});

describe('formatPublishedAt', () => {
    it('shows the instant in UTC, whatever the zone of the browser', () => {
        const text = formatPublishedAt('2026-11-10T23:30:00Z', 'es');

        expect(text).toContain('10 de noviembre de 2026');
        expect(text).toMatch(/23:30/);
        expect(text).toContain('UTC');
    });

    it('formats in the language of the page', () => {
        expect(formatPublishedAt('2026-11-10T15:00:00Z', 'en')).toContain('November 10, 2026');
    });
});
