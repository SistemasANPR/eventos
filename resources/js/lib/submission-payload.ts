import { activeFields } from '@/lib/form-text';
import { FIXED_PREFIX } from '@/lib/submission-errors';
import { participantPhotoKey } from '@/lib/submission-fields';
import type { FormField } from '@/types/form';
import type { AnswerValue, ParticipantPayload, SubmissionPayload } from '@/types/submission';

/** Lo que se lee del formulario: el cuerpo sin los datos de sesión y los archivos aún por subir. */
export interface ReadSubmission {
    values: Pick<SubmissionPayload, 'title' | 'track_id' | 'answers' | 'participants'>;
    /** Archivos elegidos por clave de campo; el envío los sube y reemplaza por sus identificadores. */
    files: Record<string, File[]>;
}

/**
 * Lee el formulario (`FormData`) y separa el bloque fijo (nombres con `s.`) de las respuestas
 * dinámicas. Una respuesta vacía no se envía: el servidor decide si el campo es obligatorio.
 * El primer participante es siempre el principal.
 */
export function readSubmission(data: FormData, fields: readonly FormField[], participantCount: number): ReadSubmission {
    const answers: Record<string, AnswerValue> = {};
    const files: Record<string, File[]> = {};

    for (const field of activeFields(fields)) {
        // El título y el tema de la propuesta llegan con nombre fijo (`s.title`, `s.track_id`) y no son respuestas.
        if (field.key.startsWith(FIXED_PREFIX)) {
            continue;
        }

        if (field.type === 'file') {
            const chosen = data.getAll(field.key).filter((entry): entry is File => entry instanceof File && entry.size > 0);
            if (chosen.length > 0) {
                files[field.key] = chosen;
            }
        } else if (field.type === 'checkbox') {
            answers[field.key] = data.has(field.key);
        } else if (field.type === 'multiple_choice') {
            const chosen = data.getAll(`${field.key}[]`).filter((entry): entry is string => typeof entry === 'string');
            if (chosen.length > 0) {
                answers[field.key] = chosen;
            }
        } else {
            const value = text(data, field.key);
            if (value !== '') {
                answers[field.key] = value;
            }
        }
    }

    const participants: ParticipantPayload[] = Array.from({ length: participantCount }, (_, index) => {
        const read = (name: string): string => text(data, `${FIXED_PREFIX}participants.${index}.${name}`);
        const participant: ParticipantPayload = {
            first_name: read('first_name'),
            last_name: read('last_name'),
            email: read('email'),
            country: read('country'),
            is_primary: index === 0,
        };

        for (const optional of ['second_last_name', 'alt_email', 'state', 'city', 'phone', 'job_title', 'company', 'bio'] as const) {
            const value = read(optional);
            if (value !== '') {
                participant[optional] = value;
            }
        }

        // La fotografía (si la hay) se sube aparte; su identificador se agrega al participante tras la subida.
        const photo = data.get(`${FIXED_PREFIX}participants.${index}.photo`);
        if (photo instanceof File && photo.size > 0) {
            files[participantPhotoKey(index)] = [photo];
        }

        return participant;
    });

    return {
        values: { title: text(data, `${FIXED_PREFIX}title`), track_id: text(data, `${FIXED_PREFIX}track_id`), answers, participants },
        files,
    };
}

function text(data: FormData, name: string): string {
    const value = data.get(name);

    return typeof value === 'string' ? value.trim() : '';
}
