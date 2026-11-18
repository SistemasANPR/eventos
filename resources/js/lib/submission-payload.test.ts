import { readSubmission } from '@/lib/submission-payload';
import type { FormField } from '@/types/form';

function field(key: string, type: FormField['type'], overrides: Partial<FormField> = {}): FormField {
    return { key, type, required: false, position: 1, identifiesAuthor: false, label: { es: key }, help: [], config: [], retiredAt: null, ...overrides };
}

describe('readSubmission', () => {
    it('separates the fixed block from the answers and marks the first participant as the main one', () => {
        const data = new FormData();
        data.append('s.title', '  Mi propuesta  ');
        data.append('s.track_id', 'track-1');
        data.append('s.participants.0.first_name', 'Ana');
        data.append('s.participants.0.last_name', 'Pérez');
        data.append('s.participants.0.email', 'ana@example.test');
        data.append('s.participants.0.country', 'MX');
        data.append('s.participants.0.bio', '');
        data.append('s.participants.1.first_name', 'Luis');
        data.append('s.participants.1.last_name', 'Gómez');
        data.append('s.participants.1.email', 'luis@example.test');
        data.append('s.participants.1.country', 'CO');
        data.append('s.participants.1.company', 'ACME');
        // Una clave de formulario llamada `title` no choca con el título fijo.
        data.append('title', 'respuesta');

        const { values } = readSubmission(data, [field('title', 'short_text')], 2);

        expect(values.title).toBe('Mi propuesta');
        expect(values.answers).toEqual({ title: 'respuesta' });
        expect(values.participants).toEqual([
            { first_name: 'Ana', last_name: 'Pérez', email: 'ana@example.test', country: 'MX', is_primary: true },
            { first_name: 'Luis', last_name: 'Gómez', email: 'luis@example.test', country: 'CO', company: 'ACME', is_primary: false },
        ]);
    });

    it('reads each answer type and omits the empty ones', () => {
        const data = new FormData();
        data.append('level', 'Básico');
        data.append('agree', 'on');
        data.append('topics[]', 'a');
        data.append('topics[]', 'b');
        data.append('empty', '');

        const { values } = readSubmission(
            data,
            [
                field('level', 'short_text', { position: 1 }),
                field('agree', 'checkbox', { position: 2 }),
                field('declined', 'checkbox', { position: 3 }),
                field('topics', 'multiple_choice', { position: 4 }),
                field('empty', 'long_text', { position: 5 }),
                field('retired', 'short_text', { position: 6, retiredAt: '2027-01-01T00:00:00Z' }),
            ],
            1,
        );

        expect(values.answers).toEqual({ level: 'Básico', agree: true, declined: false, topics: ['a', 'b'] });
    });

    it('collects the chosen files by field and ignores empty file inputs', () => {
        const data = new FormData();
        const slides = new File(['x'], 'slides.pdf', { type: 'application/pdf' });
        data.append('deck', slides);
        data.append('poster', new File([], ''));

        const { files, values } = readSubmission(data, [field('deck', 'file', { position: 1 }), field('poster', 'file', { position: 2 })], 1);

        expect(files).toEqual({ deck: [slides] });
        expect(values.answers).toEqual({});
    });
});
