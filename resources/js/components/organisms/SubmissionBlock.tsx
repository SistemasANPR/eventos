import { useEffect, useRef, useState } from 'react';

import { FormFieldInput } from '@/components/molecules/FormFieldInput';
import { BUTTON_SECONDARY, FOCUS_RING, PARTICIPANT_CARD } from '@/lib/classes';
import { participantFields, titleField, trackField } from '@/lib/submission-fields';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { SubmissionTrack } from '@/types/submission';

/** Marca de «se quitó un participante»: el foco vuelve al botón de agregar tras el cambio. */
const REMOVED = -1;

interface SubmissionBlockProps {
    locale: string;
    defaultLocale: string;
    t: Translate;
    tracks: readonly SubmissionTrack[];
    minParticipants: number;
    maxParticipants: number;
    /** Identificadores estables de los participantes: conservan lo escrito al quitar uno del medio. */
    participantIds: readonly number[];
    onAddParticipant: () => void;
    onRemoveParticipant: (id: number) => void;
    /** Errores por clave de campo (`s.title`, `s.participants.0.email`...). */
    errors: Record<string, string>;
    /** Error de la lista completa (mínimo, máximo, principal). */
    participantsError?: string;
    idPrefix?: string;
    className?: string;
}

/**
 * Bloque fijo del envío de una propuesta (plan 006, DA-1): título, eje y participantes, con el
 * mismo dibujo de campos que el formulario definido por el organizador. El primer participante es
 * el principal. Los controles no tienen estado propio: el formulario los lee al enviar.
 * Usar dentro de `FormRenderer` (ranura `leading`); no sirve fuera del envío público.
 */
export function SubmissionBlock({
    locale,
    defaultLocale,
    t,
    tracks,
    minParticipants,
    maxParticipants,
    participantIds,
    onAddParticipant,
    onRemoveParticipant,
    errors,
    participantsError,
    idPrefix = 'submit',
    className,
}: SubmissionBlockProps) {
    const [announcement, setAnnouncement] = useState('');
    const addButton = useRef<HTMLButtonElement>(null);
    const pendingFocusIndex = useRef<number | null>(null);
    const count = participantIds.length;

    useEffect(() => {
        const index = pendingFocusIndex.current;

        if (index !== null) {
            pendingFocusIndex.current = null;
            if (index === REMOVED) {
                addButton.current?.focus();
            } else {
                document.getElementById(`${idPrefix}-s.participants.${index}.first_name`)?.focus();
            }
        }
    }, [count, idPrefix]);

    const handleAdd = () => {
        pendingFocusIndex.current = count;
        setAnnouncement(t('submit.participant_added', { number: String(count + 1) }));
        onAddParticipant();
    };

    const handleRemove = (id: number) => {
        setAnnouncement(t('submit.participant_removed'));
        pendingFocusIndex.current = REMOVED;
        onRemoveParticipant(id);
    };

    const shared = { locale, defaultLocale, idPrefix, t };

    return (
        <div className={cn('space-y-8', className)}>
            <section aria-labelledby={`${idPrefix}-details`} className="space-y-6">
                <h2 id={`${idPrefix}-details`} className="text-xl font-semibold text-text">
                    {t('submit.section_details')}
                </h2>
                <FormFieldInput {...shared} field={titleField(locale, t)} error={errors['s.title']} />
                <FormFieldInput {...shared} field={trackField(tracks, locale, t)} error={errors['s.track_id']} />
            </section>

            <section aria-labelledby={`${idPrefix}-participants`} className="space-y-4">
                <h2 id={`${idPrefix}-participants`} className="text-xl font-semibold text-text">
                    {t('submit.section_participants')}
                </h2>
                <p className="text-sm text-text-muted">
                    {t('submit.participants_hint', { min: String(minParticipants), max: String(maxParticipants) })}
                </p>
                {participantsError !== undefined ? (
                    <p role="alert" data-invalid="true" tabIndex={-1} className="text-sm font-medium text-error">
                        {participantsError}
                    </p>
                ) : null}
                {participantIds.map((id, index) => (
                    <fieldset key={id} className={PARTICIPANT_CARD}>
                        <legend className="px-1 text-base font-semibold text-text">
                            {t('submit.participant_heading', { number: String(index + 1) })}
                            {index === 0 ? ` ${t('submit.participant_primary')}` : ''}
                        </legend>
                        {participantFields(index, locale, t).map((field) => (
                            <FormFieldInput
                                key={field.key}
                                {...shared}
                                field={field}
                                error={errors[field.key]}
                                inputType={field.key.endsWith('.email') ? 'email' : 'text'}
                                autoComplete={index === 0 ? autoCompleteFor(field.key) : undefined}
                            />
                        ))}
                        {index > 0 && count > minParticipants ? (
                            <button
                                type="button"
                                onClick={() => handleRemove(id)}
                                className={cn(BUTTON_SECONDARY, FOCUS_RING)}
                            >
                                {t('submit.participant_remove', { number: String(index + 1) })}
                            </button>
                        ) : null}
                    </fieldset>
                ))}
                {count < maxParticipants ? (
                    <button ref={addButton} type="button" onClick={handleAdd} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                        {t('submit.participant_add')}
                    </button>
                ) : null}
                <p role="status" className="sr-only">
                    {announcement}
                </p>
            </section>
        </div>
    );
}

/** Sugerencias del navegador solo para quien envía (el participante principal). */
function autoCompleteFor(key: string): string | undefined {
    if (key.endsWith('.first_name')) return 'given-name';
    if (key.endsWith('.last_name')) return 'family-name';
    if (key.endsWith('.email')) return 'email';
    if (key.endsWith('.country')) return 'country';
    if (key.endsWith('.job_title')) return 'organization-title';
    if (key.endsWith('.company')) return 'organization';

    return undefined;
}
