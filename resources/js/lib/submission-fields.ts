import { FIXED_PREFIX } from '@/lib/submission-errors';
import type { Translate } from '@/lib/translate';
import type { FormField } from '@/types/form';
import type { SubmissionTrack } from '@/types/submission';

/** Límites del bloque fijo: los mismos de `StoreSubmissionRequest`. */
export const TITLE_MAX = 200;

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

/** Campos de un participante; `index` es su posición actual en la lista. */
export function participantFields(index: number, locale: string, t: Translate): FormField[] {
    const prefix = `participants.${index}.`;

    return [
        fixedField(`${prefix}first_name`, 'short_text', t('submit.participant_first_name'), locale, true, { maxLength: 100 }),
        fixedField(`${prefix}last_name`, 'short_text', t('submit.participant_last_name'), locale, true, { maxLength: 100 }),
        fixedField(`${prefix}email`, 'short_text', t('submit.participant_email'), locale, true, { maxLength: 254 }),
        fixedField(`${prefix}country`, 'country', t('submit.participant_country'), locale, true),
        fixedField(`${prefix}phone`, 'short_text', t('submit.participant_phone'), locale, true, { maxLength: 30 }),
        fixedField(`${prefix}job_title`, 'short_text', t('submit.participant_job_title'), locale, true, { maxLength: 120 }),
        fixedField(`${prefix}company`, 'short_text', t('submit.participant_company'), locale, true, { maxLength: 160 }),
        fixedField(`${prefix}bio`, 'long_text', t('submit.participant_bio'), locale, true, { maxLength: 2000 }),
    ];
}
