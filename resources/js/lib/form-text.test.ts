import { activeFields, configOf, pickText } from '@/lib/form-text';
import type { FormField } from '@/types/form';

function field(overrides: Partial<FormField>): FormField {
    return {
        key: 'f_1',
        type: 'short_text',
        required: false,
        position: 1,
        identifiesAuthor: false,
        label: { es: 'Título' },
        help: [],
        config: [],
        retiredAt: null,
        ...overrides,
    };
}

describe('pickText', () => {
    it('CA-005-12: uses the requested language', () => {
        expect(pickText({ es: 'Título', en: 'Title' }, 'en', 'es')).toBe('Title');
    });

    it('CA-005-12: falls back to the default language when the translation is missing or blank', () => {
        expect(pickText({ es: 'Título' }, 'en', 'es')).toBe('Título');
        expect(pickText({ es: 'Título', en: '   ' }, 'en', 'es')).toBe('Título');
    });

    it('returns an empty string for the empty list PHP sends and for no text at all', () => {
        expect(pickText([], 'en', 'es')).toBe('');
        expect(pickText({ pt: 'Título' }, 'en', 'es')).toBe('');
    });
});

describe('configOf', () => {
    it('turns the empty list PHP sends into an empty configuration', () => {
        expect(configOf(field({ config: [] }))).toEqual({});
        expect(configOf(field({ config: { maxLength: 5 } }))).toEqual({ maxLength: 5 });
    });
});

describe('activeFields', () => {
    it('drops retired fields and sorts by position', () => {
        const fields = [
            field({ key: 'a', position: 3 }),
            field({ key: 'b', position: 1 }),
            field({ key: 'c', position: 2, retiredAt: '2027-01-01T00:00:00Z' }),
        ];

        expect(activeFields(fields).map((item) => item.key)).toEqual(['b', 'a']);
    });
});
