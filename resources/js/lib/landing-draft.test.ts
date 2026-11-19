import { countMissingTexts, missingLocales, pickOptionalText, pickText, resolveDraftBlock } from '@/lib/landing-draft';
import { createBlock } from '@/lib/landing-blocks';
import type { LandingPreviewData } from '@/types/landing';

const preview: LandingPreviewData = {
    event: {
        name: 'Congreso',
        description: null,
        startsAt: '2027-03-10T15:00:00Z',
        endsAt: '2027-03-12T23:00:00Z',
        timezone: 'America/Mexico_City',
        timeFormat: '12h',
        venueName: null,
        city: null,
    },
    calls: [],
    tracks: [],
};

describe('landing draft resolution', () => {
    it('CA-022-21: uses the requested language and falls back to the base one when it is missing or blank', () => {
        expect(pickText({ es: 'Hola', en: 'Hello' }, 'en', 'es')).toBe('Hello');
        expect(pickText({ es: 'Hola', en: '' }, 'en', 'es')).toBe('Hola');
        expect(pickText({ es: 'Hola', en: '   ' }, 'en', 'es')).toBe('Hola');
        expect(pickText({ es: 'Hola' }, 'pt', 'es')).toBe('Hola');
        expect(pickText({}, 'en', 'es')).toBe('');
        expect(pickOptionalText({ es: '' }, 'es', 'es')).toBeNull();
    });

    it('CA-022-36: finds the languages with a missing text', () => {
        expect(missingLocales({ es: 'Hola', en: '' }, ['es', 'en', 'pt'])).toEqual(['en', 'pt']);
    });

    it('CA-022-36: counts the required texts that are missing in a language', () => {
        const hero = createBlock('hero', ['es', 'en']);

        if (hero.type !== 'hero') {
            throw new Error('unexpected type');
        }

        hero.title = { es: 'Título', en: '' };

        expect(countMissingTexts(hero, 'es')).toBe(0);
        expect(countMissingTexts(hero, 'en')).toBe(1);
    });

    it('CA-022-35: resolves a hero with the fallback language and hides a button without label', () => {
        const hero = createBlock('hero', ['es', 'en']);

        if (hero.type !== 'hero') {
            throw new Error('unexpected type');
        }

        hero.title = { es: 'Título', en: '' };
        hero.buttonUrl = 'https://example.test';

        const resolved = resolveDraftBlock(hero, 'en', 'es', preview);

        expect(resolved).toMatchObject({ type: 'hero', title: 'Título', subtitle: null, button: null });
    });

    it('CA-022-35: takes event, calls and tracks from the preview data', () => {
        expect(resolveDraftBlock(createBlock('event_info', ['es']), 'es', 'es', preview)).toMatchObject({
            type: 'event_info',
            name: 'Congreso',
        });
        expect(resolveDraftBlock(createBlock('calls', ['es']), 'es', 'es', preview)).toMatchObject({
            type: 'calls',
            title: null,
            calls: [],
        });
    });
});
