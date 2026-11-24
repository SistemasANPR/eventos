import { useState } from 'react';

import { FormFieldInput } from '@/components/molecules/FormFieldInput';
import { PARTICIPANT_CARD, SECTION_CARD, SECTION_HEADING } from '@/lib/classes';
import { participantFields, titleField, trackField } from '@/lib/submission-fields';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { ParticipantFieldConfig, SubmissionTrack } from '@/types/submission';

/** Máximo de personas adicionales que ofrece la pregunta «¿incluye a otras personas participantes?». */
const MAX_EXTRA_PEOPLE = 2;

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
    /** `false` oculta la tarjeta de título y eje y deja solo la de participantes (vista previa del editor de formularios). */
    showDetails?: boolean;
    /** `false` cuando el formulario ya trae su propia pregunta de título (campo del formulario): no se repite aquí. */
    showTitle?: boolean;
    /** `false` cuando el formulario ya trae su propia pregunta de tema (campo del formulario): no se repite aquí. */
    showTrack?: boolean;
    /** `false` oculta la tarjeta de participantes (se dibuja aparte, p. ej. al final del formulario). */
    showParticipants?: boolean;
    /** Qué preguntas de los participantes pide el formulario; sin él, todas con los obligatorios de siempre. */
    participantConfig?: ParticipantFieldConfig;
}

/**
 * Bloque fijo del envío de una propuesta (plan 006, DA-1): título, eje y participantes, con el
 * mismo dibujo de campos que el formulario definido por el organizador. El primer participante es
 * el principal; la pregunta «¿incluye a otras personas participantes?» decide cuántas tarjetas más
 * se agregan (ninguna, una o dos, sin pasar del máximo ni bajar del mínimo de la convocatoria). Los
 * controles no tienen estado propio: el formulario los lee al enviar.
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
    showDetails = true,
    showTitle = true,
    showTrack = true,
    showParticipants = true,
    participantConfig,
}: SubmissionBlockProps) {
    const [announcement, setAnnouncement] = useState('');
    const count = participantIds.length;

    // Personas adicionales que se pueden elegir: de lo que exige el mínimo a lo que permite el máximo (hasta dos).
    const lowestExtra = Math.max(0, minParticipants - 1);
    const highestExtra = Math.min(MAX_EXTRA_PEOPLE, maxParticipants - 1);
    const extraChoices = Array.from({ length: Math.max(0, highestExtra - lowestExtra + 1) }, (_, offset) => lowestExtra + offset);

    const chooseExtra = (extra: number) => {
        const target = extra + 1;

        if (target > count) {
            for (let added = count; added < target; added += 1) {
                onAddParticipant();
            }
            setAnnouncement(t('submit.participant_added', { number: String(target) }));
        } else if (target < count) {
            participantIds.slice(target).forEach((id) => onRemoveParticipant(id));
            setAnnouncement(t('submit.participant_removed'));
        }
    };

    const shared = { locale, defaultLocale, idPrefix, t };

    const renderParticipant = (id: number, index: number) => (
        <fieldset key={id} className={PARTICIPANT_CARD}>
            <legend className="px-1 text-base font-semibold text-text">
                {t('submit.participant_heading', { number: String(index + 1) })}
                {index === 0 ? ` ${t('submit.participant_primary')}` : ''}
            </legend>
            {participantFields(index, locale, t, participantConfig).map((field) => (
                <FormFieldInput
                    key={field.key}
                    {...shared}
                    field={field}
                    error={errors[field.key]}
                    inputType={
                        field.key.endsWith('.email') || field.key.endsWith('.alt_email') ? 'email' : isPhoneKey(field.key) ? 'tel' : 'text'
                    }
                    autoComplete={index === 0 ? autoCompleteFor(field.key) : undefined}
                />
            ))}
        </fieldset>
    );

    return (
        <div className={cn('space-y-8', className)}>
            {showDetails && (showTitle || showTrack) ? (
                <section aria-labelledby={`${idPrefix}-details`} className={cn(SECTION_CARD, 'space-y-6')}>
                    <h2 id={`${idPrefix}-details`} className={SECTION_HEADING}>
                        {t('submit.section_details')}
                    </h2>
                    {showTitle ? <FormFieldInput {...shared} field={titleField(locale, t)} error={errors['s.title']} /> : null}
                    {showTrack ? <FormFieldInput {...shared} field={trackField(tracks, locale, t)} error={errors['s.track_id']} /> : null}
                </section>
            ) : null}

            {showParticipants ? (
                <section aria-labelledby={`${idPrefix}-participants`} className={cn(SECTION_CARD, 'space-y-4')}>
                    <h2 id={`${idPrefix}-participants`} className={SECTION_HEADING}>
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

                    {participantIds.slice(0, 1).map((id) => renderParticipant(id, 0))}

                    {extraChoices.length > 1 ? (
                        <fieldset className="space-y-2">
                            <legend className="text-base font-medium text-text">{t('submit.participants_extra_question')}</legend>
                            {extraChoices.map((extra) => (
                                <div key={extra} className="flex min-h-11 items-center gap-3">
                                    <input
                                        id={`${idPrefix}-extra-${extra}`}
                                        type="radio"
                                        name={`${idPrefix}-extra-people`}
                                        checked={count - 1 === extra}
                                        onChange={() => chooseExtra(extra)}
                                        className="size-5 accent-primary"
                                    />
                                    <label htmlFor={`${idPrefix}-extra-${extra}`} className="text-base text-text">
                                        {t(`submit.participants_extra_${extra}`)}
                                    </label>
                                </div>
                            ))}
                        </fieldset>
                    ) : null}

                    {participantIds.slice(1).map((id, offset) => renderParticipant(id, offset + 1))}

                    <p role="status" className="sr-only">
                        {announcement}
                    </p>
                </section>
            ) : null}
        </div>
    );
}

function isPhoneKey(key: string): boolean {
    return key.endsWith('.phone');
}

/** Sugerencias del navegador solo para quien envía (el participante principal). */
function autoCompleteFor(key: string): string | undefined {
    if (key.endsWith('.first_name')) return 'given-name';
    if (key.endsWith('.last_name')) return 'family-name';
    if (key.endsWith('.email') || key.endsWith('.alt_email')) return 'email';
    if (key.endsWith('.country')) return 'country';
    if (key.endsWith('.phone')) return 'tel';
    if (key.endsWith('.job_title')) return 'organization-title';
    if (key.endsWith('.company')) return 'organization';
    if (key.endsWith('.state')) return 'address-level1';
    if (key.endsWith('.city')) return 'address-level2';

    return undefined;
}
