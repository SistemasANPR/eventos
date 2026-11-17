import { participantFields, titleField, trackField } from '@/lib/submission-fields';
import { createTranslate } from '@/lib/translate';

const t = createTranslate({ submit: { field_title: 'Título', field_track: 'Eje', participant_full_name: 'Nombre' } });

describe('fixed block fields', () => {
    it('prefixes every key so it never collides with a form field key', () => {
        const keys = [titleField('es', t), trackField([], 'es', t), ...participantFields(0, 'es', t)].map((field) => field.key);

        expect(keys.every((key) => key.startsWith('s.'))).toBe(true);
    });

    it('limits the title to 200 characters and requires title and track', () => {
        expect(titleField('es', t)).toMatchObject({ required: true, config: { maxLength: 200 } });
        expect(trackField([{ id: 'a', name: 'Ciencia' }], 'es', t)).toMatchObject({
            type: 'single_choice',
            required: true,
            config: { options: [{ value: 'a', label: { es: 'Ciencia' } }] },
        });
    });

    it('builds the participant fields by position with the limits of the server', () => {
        const fields = participantFields(2, 'es', t);

        expect(fields.map((field) => field.key)).toEqual([
            's.participants.2.full_name',
            's.participants.2.email',
            's.participants.2.country',
            's.participants.2.job_title',
            's.participants.2.company',
            's.participants.2.bio',
        ]);
        expect(fields.filter((field) => field.required).map((field) => field.key)).toEqual([
            's.participants.2.full_name',
            's.participants.2.email',
            's.participants.2.country',
        ]);
    });
});
