/** Prefijo de los nombres del bloque fijo: una clave de formulario nunca lo lleva, así no choca con las respuestas. */
export const FIXED_PREFIX = 's.';

export interface MappedSubmissionErrors {
    /** Errores por clave de campo de `FormFieldInput`: del bloque fijo (`s.title`...) y de respuestas (`summary`). */
    fields: Record<string, string>;
    consent?: string;
    thirdParties?: string;
    participants?: string;
    turnstile?: string;
    /** Errores sin campo visible (sesión del formulario, versión, idioma, tamaño de respuestas). */
    general: string[];
}

const PARTICIPANT_ERROR = /^participants\.(\d+)\.(\w+)$/;
const ANSWER_ERROR = /^answers\.([^.]+)(?:\..*)?$/;

/**
 * Convierte los errores de validación del servidor (`participants.0.email`, `answers.summary`) en los
 * que sabe pintar el formulario. Conserva el primer mensaje de cada campo.
 */
export function mapServerErrors(errors: Record<string, string>): MappedSubmissionErrors {
    const mapped: MappedSubmissionErrors = { fields: {}, general: [] };

    for (const [key, message] of Object.entries(errors)) {
        const participant = PARTICIPANT_ERROR.exec(key);
        const answer = ANSWER_ERROR.exec(key);

        if (key === 'title' || key === 'track_id') {
            mapped.fields[`${FIXED_PREFIX}${key}`] ??= message;
        } else if (participant !== null) {
            mapped.fields[`${FIXED_PREFIX}participants.${participant[1]}.${participant[2]}`] ??= message;
        } else if (answer !== null) {
            mapped.fields[answer[1] as string] ??= message;
        } else if (key === 'consent' || key === 'privacy_notice_version_id') {
            mapped.consent ??= message;
        } else if (key === 'third_parties_authorized') {
            mapped.thirdParties = message;
        } else if (key === 'participants') {
            mapped.participants = message;
        } else if (key === 'turnstile_token') {
            mapped.turnstile = message;
        } else {
            mapped.general.push(message);
        }
    }

    return mapped;
}

/** `true` si hay al menos un error que mostrar. */
export function hasErrors(mapped: MappedSubmissionErrors): boolean {
    return (
        Object.keys(mapped.fields).length > 0 ||
        mapped.general.length > 0 ||
        [mapped.consent, mapped.thirdParties, mapped.participants, mapped.turnstile].some((message) => message !== undefined)
    );
}
