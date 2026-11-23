import { participantFields, titleField, trackField } from '@/lib/submission-fields';
import { createTranslate } from '@/lib/translate';

const t = createTranslate({
    submit: { field_title: 'Título', field_track: 'Eje', participant_first_name: 'Nombre', participant_last_name: 'Apellido' },
});

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

        const keys = [
            'first_name',
            'last_name',
            'second_last_name',
            'email',
            'alt_email',
            'mobile',
            'phone',
            'company',
            'job_title',
            'country',
            'state',
            'city',
            'bio',
            'photo',
        ].map((name) => `s.participants.2.${name}`);

        expect(fields.map((field) => field.key)).toEqual(keys);
        // Son obligatorios todos menos el apellido materno, el correo alternativo y la fotografía.
        expect(fields.filter((field) => field.required).map((field) => field.key)).toEqual(
            keys.filter((key) => !key.endsWith('.second_last_name') && !key.endsWith('.alt_email') && !key.endsWith('.photo')),
        );
        // El celular: exactamente 14 dígitos y solo números.
        expect(fields.find((field) => field.key.endsWith('.mobile'))?.config).toEqual({ minLength: 14, maxLength: 14, digitsOnly: true });
    });
});
