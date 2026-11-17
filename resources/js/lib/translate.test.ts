import { createTranslate } from '@/lib/translate';

const t = createTranslate({
    event: { when: 'Fechas y horario', language_option: 'Ver en :language' },
    title: 'Título',
});

describe('createTranslate', () => {
    it('finds a nested key by its dotted path', () => {
        expect(t('event.when')).toBe('Fechas y horario');
    });

    it('finds a top-level key', () => {
        expect(t('title')).toBe('Título');
    });

    it('replaces :placeholders', () => {
        expect(t('event.language_option', { language: 'English' })).toBe('Ver en English');
    });

    it('returns the key itself when it does not exist, so the gap is visible', () => {
        expect(t('event.missing')).toBe('event.missing');
        expect(t('nope.nested.deep')).toBe('nope.nested.deep');
    });

    it('returns the key when the path points to a group, not a text', () => {
        expect(t('event')).toBe('event');
    });
});
