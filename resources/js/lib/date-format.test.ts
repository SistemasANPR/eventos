import { formatEventDates, formatEventInstant, formatLocalDate, formatLocalTime, formatPublishedAt, formatTimeZoneOffset } from '@/lib/date-format';

/** Ejecuta `check` con la zona horaria del navegador (proceso de Node) cambiada y la restaura al terminar. */
function inBrowserZone(zone: string, check: () => void): void {
    const original = process.env.TZ;
    process.env.TZ = zone;

    try {
        check();
    } finally {
        if (original === undefined) {
            delete process.env.TZ;
        } else {
            process.env.TZ = original;
        }
    }
}

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
    it('CA-023-12: shows 12 hours with a day period when the event uses 12 hours', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '12h');

        expect(text).toMatch(/\b9:00\s?a\.?\s?m\.?/i);
        expect(text).toMatch(/\b5:00\s?p\.?\s?m\.?/i);
    });

    it('CA-023-12: shows 24 hours without a day period when the event uses 24 hours', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es', '24h');

        // ICU escribe la hora de 24 h en español sin cero inicial («9:00»), a diferencia del panel («09:00 h»).
        expect(text).toMatch(/\b9:00\b/);
        expect(text).toMatch(/\b17:00\b/);
        expect(text).not.toMatch(/\b[ap]\.?\s?m\./i);
    });

    it('CA-023-12: follows the option in English too, and never writes midnight as 24:00', () => {
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

describe('formatLocalTime', () => {
    it('CA-029-12: shows 23:30 in 12 hours as 11:30 p. m. in Spanish', () => {
        expect(formatLocalTime('23:30', 'es', '12h')).toMatch(/^11:30\s?p\.?\s?m\.?$/i);
    });

    it('CA-029-12: shows 23:30 in 24 hours without a day period', () => {
        expect(formatLocalTime('23:30', 'es', '24h')).toBe('23:30');
    });

    it('CA-029-12: follows the language of the page in 12 hours', () => {
        expect(formatLocalTime('09:05', 'en', '12h')).toMatch(/^9:05\s?AM$/);
    });

    it('CA-029-12: never writes midnight as 24:00', () => {
        expect(formatLocalTime('00:15', 'en', '24h')).not.toMatch(/^24/);
    });

    it.each(['America/Mexico_City', 'Asia/Tokyo', 'Pacific/Kiritimati', 'UTC'])(
        'CA-029-12: does not shift the local time of the event for a browser in %s',
        (zone) => {
            inBrowserZone(zone, () => {
                expect(formatLocalTime('23:30', 'es', '24h')).toBe('23:30');
                expect(formatLocalTime('00:00', 'es', '24h')).toBe('00:00');
            });
        },
    );
});

describe('formatLocalDate', () => {
    it('CA-029-12: writes the local day of the event without shifting it', () => {
        expect(formatLocalDate('2026-11-20', 'es')).toContain('20 de noviembre de 2026');
        expect(formatLocalDate('2026-11-20', 'en')).toContain('November 20, 2026');
    });

    it.each(['Pacific/Honolulu', 'Pacific/Kiritimati'])('CA-029-12: keeps the day for a browser in %s', (zone) => {
        inBrowserZone(zone, () => {
            expect(formatLocalDate('2026-11-20', 'en')).toContain('November 20');
        });
    });

    it('offers a short form for navigation', () => {
        const text = formatLocalDate('2026-11-20', 'es', 'short');

        expect(text).toContain('20');
        expect(text).not.toContain('2026');
    });
});

describe('formatTimeZoneOffset', () => {
    it('names the zone of the event with its offset from UTC', () => {
        expect(formatTimeZoneOffset('America/Mexico_City', 'es', '2027-03-10T15:00:00Z')).toBe('America/Mexico_City (GMT-6)');
    });

    it('follows the daylight saving time of the given instant', () => {
        expect(formatTimeZoneOffset('America/New_York', 'en', '2027-07-10T15:00:00Z')).toBe('America/New_York (GMT-4)');
    });
});
