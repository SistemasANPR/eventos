import { useEffect, useMemo, useRef, useState } from 'react';

import { TurnstileWidget } from '@/components/infrastructure/TurnstileWidget';
import { AcceptanceField } from '@/components/molecules/AcceptanceField';
import { FormRenderer } from '@/components/organisms/FormRenderer';
import { SubmissionBlock } from '@/components/organisms/SubmissionBlock';
import { BUTTON_SECONDARY, FOCUS_RING, SECTION_CARD, SECTION_HEADING } from '@/lib/classes';
import { FIXED_PREFIX, hasErrors, mapServerErrors } from '@/lib/submission-errors';
import { participantPhotoKey } from '@/lib/submission-fields';
import { readSubmission } from '@/lib/submission-payload';
import type { Translate } from '@/lib/translate';
import { UploadFailedError, uploadSubmissionFile } from '@/lib/upload-submission-file';
import { cn } from '@/lib/utils';
import type { AnswerValue, PublicSubmissionForm, SubmissionPayload, SubmitFailure } from '@/types/submission';

interface SubmissionFormProps {
    form: PublicSubmissionForm;
    locale: string;
    t: Translate;
    /** Errores de validación del servidor por campo (`title`, `participants.0.email`, `answers.summary`...). */
    errors: Record<string, string>;
    /** Último rechazo del servidor que no sale de la página (convocatoria cerrada, formulario cambiado, límite...). */
    failure: SubmitFailure | null;
    isProcessing: boolean;
    isRefreshing: boolean;
    /** Recibe el cuerpo completo del envío, con los archivos ya subidos. */
    onSubmit: (payload: SubmissionPayload) => void;
    /** Pide al servidor la definición vigente y credenciales nuevas, sin perder lo escrito. */
    onRefresh: () => void;
}

/** Códigos tras los cuales hay que pedir credenciales nuevas al servidor. */
const NEEDS_REFRESH = ['FORM_OUTDATED', 'IDEMPOTENCY_PAYLOAD_MISMATCH'];

/**
 * Formulario público de envío de una propuesta (plan 006, H-006-7): bloque fijo, campos del
 * formulario del organizador, consentimiento y verificación anti-robots. Lo escrito vive en los
 * controles y NO se pierde ante un 422, un 409 o una convocatoria cerrada: solo cambian los
 * errores. El foco pasa al primer error y cada mensaje queda asociado a su campo.
 * Usar en la página de envío; el viaje con el servidor (Inertia) lo hace la página.
 */
export function SubmissionForm({ form, locale, t, errors, failure, isProcessing, isRefreshing, onSubmit, onRefresh }: SubmissionFormProps) {
    const container = useRef<HTMLDivElement>(null);
    const alert = useRef<HTMLDivElement>(null);
    const nextParticipantId = useRef(Math.max(1, form.minParticipants));
    const uploaded = useRef(new Map<string, string>());

    const [participantIds, setParticipantIds] = useState<number[]>(() =>
        Array.from({ length: Math.max(1, form.minParticipants) }, (_, index) => index),
    );
    const [consent, setConsent] = useState(false);
    const [thirdParties, setThirdParties] = useState(false);
    const [token, setToken] = useState<string | null>(null);
    const [resetCount, setResetCount] = useState(0);
    const [turnstileMessage, setTurnstileMessage] = useState<string | undefined>(undefined);
    const [isUploading, setIsUploading] = useState(false);
    const [uploadErrors, setUploadErrors] = useState<Record<string, string>>({});
    // Credenciales con las que se abrió el formulario: un 422 recarga la página con otras nuevas, pero
    // las cargas ya hechas pertenecen a las primeras. Solo se cambian al refrescar la versión.
    const [credentials, setCredentials] = useState({ key: form.idempotencyKey, token: form.uploadToken, version: form.version });
    const [noticeId, setNoticeId] = useState(form.privacyNotice.id);

    if (credentials.version !== form.version) {
        setCredentials({ key: form.idempotencyKey, token: form.uploadToken, version: form.version });
    }

    // Un aviso nuevo exige aceptarlo de nuevo (CA-006-52).
    if (noticeId !== form.privacyNotice.id) {
        setNoticeId(form.privacyNotice.id);
        setConsent(false);
    }

    const mapped = useMemo(() => mapServerErrors(errors), [errors]);
    const fieldErrors = useMemo(() => ({ ...mapped.fields, ...uploadErrors }), [mapped, uploadErrors]);
    const hasSiteKey = form.turnstileSiteKey !== '';
    const turnstileError = mapped.turnstile ?? turnstileMessage;
    const isClosed = failure?.code === 'CALL_NOT_OPEN';
    const needsRefresh = (failure !== null && NEEDS_REFRESH.includes(failure.code)) || mapped.general.length > 0;
    const hasFieldErrors = hasErrors({ ...mapped, general: [] }) || Object.keys(uploadErrors).length > 0;
    const hasAlert = failure !== null || mapped.general.length > 0 || hasFieldErrors;

    // Foco al primer error cada vez que llegan errores nuevos (CA-006-56, CA-006-58).
    useEffect(() => {
        const root = container.current;

        if (root === null || !hasAlert) {
            return;
        }

        const hasGeneralError = failure !== null || mapped.general.length > 0;
        const firstInvalid = root.querySelector<HTMLElement>('[aria-invalid="true"], [data-invalid="true"]');

        if (!hasGeneralError && firstInvalid !== null) {
            firstInvalid.focus();
        } else {
            alert.current?.focus();
        }
    }, [errors, failure, uploadErrors, turnstileMessage, hasAlert, mapped.general.length]);

    const handleAddParticipant = () => {
        const id = nextParticipantId.current++;

        setParticipantIds((ids) => [...ids, id]);
    };

    const handleRemoveParticipant = (id: number) => {
        setParticipantIds((ids) => ids.filter((current) => current !== id));
    };

    const handleToken = (value: string | null) => {
        setToken(value);

        if (value !== null) {
            setTurnstileMessage(undefined);
        }
    };

    const handleTurnstileLoadError = () => {
        setTurnstileMessage(t('submit.turnstile_failed'));
    };

    const uploadFiles = async (files: Record<string, File[]>): Promise<Record<string, AnswerValue>> => {
        const answers: Record<string, AnswerValue> = {};

        for (const [fieldKey, list] of Object.entries(files)) {
            const ids: string[] = [];

            for (const file of list) {
                const cacheKey = [credentials.token, fieldKey, file.name, file.size, file.lastModified].join('|');
                let id = uploaded.current.get(cacheKey);

                if (id === undefined) {
                    try {
                        id = await uploadSubmissionFile(form.uploadUrl, file, fieldKey, credentials.token);
                    } catch (error) {
                        const message =
                            error instanceof UploadFailedError && error.message !== ''
                                ? error.message
                                : t('submit.error_upload', { name: file.name });
                        throw new FieldUploadError(fieldKey, message);
                    }

                    uploaded.current.set(cacheKey, id);
                }

                ids.push(id);
            }

            answers[fieldKey] = ids;
        }

        return answers;
    };

    const submit = async (data: FormData) => {
        if (isProcessing || isUploading || isClosed) {
            return;
        }

        setUploadErrors({});

        if (hasSiteKey && token === null) {
            setTurnstileMessage(t('submit.turnstile_loading'));

            return;
        }

        const { values, files } = readSubmission(data, form.fields, participantIds.length);
        let fileAnswers: Record<string, AnswerValue> = {};

        setIsUploading(true);

        try {
            fileAnswers = await uploadFiles(files);
        } catch (error) {
            if (error instanceof FieldUploadError) {
                const photo = /^participant_photo_(\d+)$/.exec(error.fieldKey);

                setUploadErrors({ [photo === null ? error.fieldKey : `${FIXED_PREFIX}participants.${photo[1]}.photo`]: error.message });
            }

            return;
        } finally {
            setIsUploading(false);
        }

        onSubmit({
            idempotency_key: credentials.key,
            upload_token: credentials.token,
            form_version: form.version,
            locale,
            title: values.title,
            track_id: values.track_id,
            answers: Object.fromEntries(Object.entries({ ...values.answers, ...fileAnswers }).filter(([key]) => !isPhotoKey(key))),
            participants: values.participants.map((participant, index) => {
                const photoId = fileAnswers[participantPhotoKey(index)];

                return Array.isArray(photoId) && photoId[0] !== undefined ? { ...participant, photo_file_id: photoId[0] } : participant;
            }),
            consent,
            privacy_notice_version_id: form.privacyNotice.id,
            third_parties_authorized: thirdParties && participantIds.length > 1,
            turnstile_token: token ?? '',
        });

        // Un token vale para un solo envío: se pide otro de inmediato por si el servidor rechaza este.
        setToken(null);
        setResetCount((count) => count + 1);
    };

    const handleSubmit = (data: FormData) => {
        void submit(data);
    };

    const submitLabel = isUploading ? t('submit.uploading') : isProcessing ? t('submit.sending') : t('submit.send_action');

    const isParticipantsLast = form.participantsPosition === 'bottom';
    const block = (parts: { showDetails: boolean; showParticipants: boolean }) => (
        <SubmissionBlock
            locale={locale}
            defaultLocale={form.defaultLocale}
            t={t}
            tracks={form.tracks}
            minParticipants={form.minParticipants}
            maxParticipants={form.maxParticipants}
            participantIds={participantIds}
            onAddParticipant={handleAddParticipant}
            onRemoveParticipant={handleRemoveParticipant}
            errors={fieldErrors}
            participantsError={mapped.participants}
            participantConfig={form.participantFields}
            showDetails={parts.showDetails}
            showParticipants={parts.showParticipants}
        />
    );

    // El formulario decide si los participantes van antes o después de las demás preguntas.
    const leading = block({ showDetails: true, showParticipants: !isParticipantsLast });

    const consentSection = (
        <section aria-labelledby="submit-consent-heading" className={cn(SECTION_CARD, 'space-y-4')}>
            <h2 id="submit-consent-heading" className={SECTION_HEADING}>
                {t('submit.section_consent')}
            </h2>
            <AcceptanceField id="submit-consent" isChecked={consent} onChange={setConsent} error={mapped.consent}>
                {t('submit.consent_prefix')}
                <a
                    href={form.privacyNotice.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={cn('text-primary underline underline-offset-4', FOCUS_RING)}
                >
                    {t('submit.consent_link')}
                    <span className="sr-only"> ({t('submit.consent_new_tab')})</span>
                </a>
                {t('submit.consent_suffix', { version: String(form.privacyNotice.version) })}
            </AcceptanceField>
            {participantIds.length > 1 ? (
                <AcceptanceField id="submit-third-parties" isChecked={thirdParties} onChange={setThirdParties} error={mapped.thirdParties}>
                    {t('submit.third_parties')}
                </AcceptanceField>
            ) : null}
            <div
                data-invalid={turnstileError !== undefined ? 'true' : undefined}
                tabIndex={turnstileError !== undefined ? -1 : undefined}
                className="space-y-2"
            >
                <p className="text-sm font-medium text-text">{t('submit.turnstile_label')}</p>
                {hasSiteKey ? (
                    <TurnstileWidget
                        siteKey={form.turnstileSiteKey}
                        locale={locale}
                        onToken={handleToken}
                        onLoadError={handleTurnstileLoadError}
                        resetCount={resetCount}
                    />
                ) : (
                    <p className="text-sm text-text-muted">{t('submit.turnstile_missing')}</p>
                )}
                {turnstileError !== undefined ? (
                    <p role="alert" className="text-sm font-medium text-error">
                        {turnstileError}
                    </p>
                ) : null}
            </div>
        </section>
    );

    const trailing = (
        <div className="space-y-8">
            {isParticipantsLast ? block({ showDetails: false, showParticipants: true }) : null}
            {consentSection}
        </div>
    );

    return (
        <div ref={container} className="space-y-6">
            <div ref={alert} tabIndex={-1} role="alert" aria-live="assertive" className={cn(!hasAlert && 'hidden')}>
                {hasAlert ? (
                    <div className="space-y-3 rounded-2xl border border-error bg-error-bg p-4 text-error">
                        <p className="text-base font-semibold">{t('submit.error_title')}</p>
                        {failureMessage(failure, t) !== null ? <p className="text-base">{failureMessage(failure, t)}</p> : null}
                        {mapped.general.map((message) => (
                            <p key={message} className="text-base">
                                {message}
                            </p>
                        ))}
                        {hasFieldErrors && failure === null ? <p className="text-base">{t('submit.error_fields')}</p> : null}
                        {needsRefresh ? (
                            <button type="button" onClick={onRefresh} disabled={isRefreshing} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                                {isRefreshing ? t('submit.reloading') : t('submit.reload_form')}
                            </button>
                        ) : null}
                    </div>
                ) : null}
            </div>
            <FormRenderer
                mode="public"
                fields={form.fields}
                locale={locale}
                defaultLocale={form.defaultLocale}
                t={t}
                errors={fieldErrors}
                onSubmit={handleSubmit}
                leading={leading}
                fieldsTitle={form.extraSectionTitle ?? t('submit.section_questions')}
                trailing={trailing}
                isSubmitting={isProcessing || isUploading}
                isSubmitDisabled={isClosed}
                submitLabel={submitLabel}
            />
        </div>
    );
}

/** Las fotografías de participantes viajan en cada participante, no entre las respuestas del formulario. */
function isPhotoKey(key: string): boolean {
    return key.startsWith('participant_photo_');
}

/** Error de la subida de un campo concreto: se muestra junto a ese campo. */
class FieldUploadError extends Error {
    constructor(
        readonly fieldKey: string,
        message: string,
    ) {
        super(message);
    }
}

/** Texto de un rechazo del servidor; los que traen mensaje propio ya vienen en el idioma de la página. */
function failureMessage(failure: SubmitFailure | null, t: Translate): string | null {
    if (failure === null) {
        return null;
    }

    switch (failure.code) {
        case 'CALL_NOT_OPEN':
            return t('submit.error_call_not_open');
        case 'FORM_OUTDATED':
        case 'IDEMPOTENCY_PAYLOAD_MISMATCH':
            return t('submit.error_session');
        case 'TOO_MANY_REQUESTS':
            return t('submit.error_too_many', { minutes: String(Math.max(1, Math.ceil((failure.retryAfterSeconds ?? 60) / 60))) });
        case 'NETWORK':
            return t('submit.error_network');
        default:
            return failure.message ?? t('submit.error_generic');
    }
}
