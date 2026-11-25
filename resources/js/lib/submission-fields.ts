import { FIXED_PREFIX } from '@/lib/submission-errors';
import type { Translate } from '@/lib/translate';
import type { FormField } from '@/types/form';
import type { ParticipantFieldConfig, SubmissionTrack } from '@/types/submission';

/** Límites del bloque fijo: los mismos de `StoreSubmissionRequest`. */
export const TITLE_MAX = 200;

/** Clave con que el servidor reconoce la fotografía del participante en la posición `index` (0, 1, 2…). */
export function participantPhotoKey(index: number): string {
    return `participant_photo_${index}`;
}

function fixedField(
    key: string,
    type: FormField['type'],
    label: string,
    locale: string,
    required: boolean,
    config: FormField['config'] = [],
): FormField {
    return {
        key: `${FIXED_PREFIX}${key}`,
        type,
        required,
        position: 0,
        identifiesAuthor: false,
        label: { [locale]: label },
        help: [],
        config,
        retiredAt: null,
    };
}

export function titleField(locale: string, t: Translate): FormField {
    return fixedField('title', 'short_text', t('submit.field_title'), locale, true, { maxLength: TITLE_MAX });
}

/** El eje se elige entre los activos del evento: si no hay ninguno, el bloque no ofrece el campo. */
export function trackField(tracks: readonly SubmissionTrack[], locale: string, t: Translate): FormField {
    return fixedField('track_id', 'single_choice', t('submit.field_track'), locale, true, {
        options: tracks.map((track) => ({ value: track.id, label: { [locale]: track.name } })),
    });
}

/** Preguntas del participante que no se pueden apagar ni volver opcionales (igual que `FormSettings::LOCKED`). */
const LOCKED = ['first_name', 'last_name', 'email', 'country'];

/**
 * Campos de un participante; `index` es su posición actual en la lista. `config` dice cuáles pide el formulario y
 * cuáles son obligatorios; sin él, se piden todos con los obligatorios de siempre.
 */
export function participantFields(index: number, locale: string, t: Translate, config?: ParticipantFieldConfig): FormField[] {
    const prefix = `participants.${index}.`;
    const field = (
        name: string,
        type: FormField['type'],
        label: string,
        required: boolean,
        settings: FormField['config'] = [],
    ): FormField | null => {
        const own = config?.[name];

        if (own !== undefined && !own.enabled && !LOCKED.includes(name)) {
            return null;
        }

        const isRequired = LOCKED.includes(name) ? true : (own?.required ?? required);

        return fixedField(`${prefix}${name}`, type, label, locale, isRequired, settings);
    };

    return [
        field('first_name', 'short_text', t('submit.participant_first_name'), true, { maxLength: 100 }),
        field('last_name', 'short_text', t('submit.participant_last_name'), true, { maxLength: 100 }),
        field('second_last_name', 'short_text', t('submit.participant_second_last_name'), false, { maxLength: 100 }),
        field('email', 'short_text', t('submit.participant_email'), true, { maxLength: 254 }),
        field('alt_email', 'short_text', t('submit.participant_alt_email'), false, { maxLength: 254 }),
        field('phone', 'short_text', t('submit.participant_phone'), true, { maxLength: 30 }),
        field('company', 'short_text', t('submit.participant_company'), true, { maxLength: 160 }),
        field('job_title', 'short_text', t('submit.participant_job_title'), true, { maxLength: 120 }),
        field('country', 'country', t('submit.participant_country'), true),
        field('state', 'short_text', t('submit.participant_state'), true, { maxLength: 120 }),
        field('city', 'short_text', t('submit.participant_city'), true, { maxLength: 120 }),
        field('bio', 'long_text', t('submit.participant_bio'), true, { maxLength: 2000 }),
        // Fotografía (JPG o PNG): se sube aparte y se liga al participante al enviar.
        field('photo', 'file', t('submit.participant_photo'), false, { accept: ['png', 'jpg'], maxFiles: 1 }),
    ].filter((item): item is FormField => item !== null);
}
