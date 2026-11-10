import { formatEventDates } from '@/lib/date-format';

const STARTS_AT = '2027-03-10T15:00:00Z';
const ENDS_AT = '2027-03-12T23:00:00Z';

describe('formatEventDates', () => {
    it('shows the times in the time zone of the event, not in UTC', () => {
        const text = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es');

        expect(text).toMatch(/\b9:00\b/);
        expect(text).not.toMatch(/\b15:00\b/);
    });

    it('formats in the language of the page', () => {
        const spanish = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'es');
        const english = formatEventDates(STARTS_AT, ENDS_AT, 'America/Mexico_City', 'en');

        expect(spanish).toContain('marzo');
        expect(english).toContain('March');
    });

    it('moves the day when the zone crosses midnight', () => {
        const text = formatEventDates('2027-03-10T02:00:00Z', '2027-03-10T05:00:00Z', 'America/Mexico_City', 'en');

        expect(text).toContain('March 9');
    });
});
