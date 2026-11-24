import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { TextLink } from '@/components/atoms/TextLink';
import { FormFieldInput } from '@/components/molecules/FormFieldInput';
import { FormRenderer } from '@/components/organisms/FormRenderer';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_SECONDARY, PAGE_TITLE, PARTICIPANT_CARD } from '@/lib/classes';
import { mapServerErrors } from '@/lib/submission-errors';
import { participantFields, titleField, trackField } from '@/lib/submission-fields';
import { readSubmission } from '@/lib/submission-payload';
import { createTranslate } from '@/lib/translate';
import type { AuthorEditData, AuthorEditParticipant } from '@/types/author';
import type { PublicOrganization } from '@/types/event';
import type { SharedProps } from '@/types/inertia';

interface EditProps {
    event: { name: string };
    organization: PublicOrganization;
    reference: string;
    /** Participantes actuales, en orden: el correo se muestra pero no se edita (DA-6). */
    participants: AuthorEditParticipant[];
    edit: AuthorEditData;
    updateUrl: string;
    showUrl: string;
}

type EditPageProps = SharedProps & { errors: Record<string, string> };

/**
 * Editar la propuesta: `/{organización}/events/{evento}/my-submission/edit`. Reutiliza `FormRenderer` con los
 * valores actuales. El bloque fijo (título, eje y datos de los participantes) va en `leading`. La `version` de
 * la propuesta y la del formulario se fijan al abrir la pantalla: si otra edición o un cambio del formulario
 * se adelantó, el servidor rechaza el guardado y la persona decide recargar (lo escrito no se pierde solo).
 */
export default function Edit({ event, organization, reference, participants, edit, updateUrl, showUrl }: EditProps) {
    const { translations, locale, errors } = usePage<EditPageProps>().props;
    const t = useMemo(() => createTranslate({ ...translations, ...edit.translations }), [translations, edit.translations]);
    const [openedVersion] = useState(edit.version);
    const [openedFormVersion] = useState(edit.formVersion);
    const [isProcessing, setIsProcessing] = useState(false);

    const mapped = mapServerErrors(errors);
    const editError = errors.edit;
    const hasFieldErrors = Object.keys(mapped.fields).length > 0;

    const handleSubmit = (data: FormData) => {
        const { values } = readSubmission(data, edit.fields, participants.length);

        router.patch(
            updateUrl,
            { version: openedVersion, form_version: openedFormVersion, ...values },
            { preserveState: true, preserveScroll: true, onStart: () => setIsProcessing(true), onFinish: () => setIsProcessing(false) },
        );
    };

    const handleReload = () => router.visit(updateUrl, { method: 'get', preserveState: false });

    const leading = (
        <>
            <section aria-labelledby="edit-details" className="space-y-4">
                <h2 id="edit-details" className="text-2xl font-semibold text-text">
                    {t('submit.section_details')}
                </h2>
                <FormFieldInput
                    field={titleField(locale, t)}
                    locale={locale}
                    defaultLocale={edit.defaultLocale}
                    idPrefix="edit"
                    t={t}
                    error={mapped.fields['s.title']}
                    initialValue={edit.initialValues['s.title']}
                />
                <FormFieldInput
                    field={trackField(edit.tracks, locale, t)}
                    locale={locale}
                    defaultLocale={edit.defaultLocale}
                    idPrefix="edit"
                    t={t}
                    error={mapped.fields['s.track_id']}
                    initialValue={edit.initialValues['s.track_id']}
                />
            </section>
            <section aria-labelledby="edit-participants" className="space-y-4">
                <h2 id="edit-participants" className="text-2xl font-semibold text-text">
                    {t('submit.section_participants')}
                </h2>
                {participants.map((participant, index) => (
                    <fieldset key={participant.email} className={PARTICIPANT_CARD}>
                        <legend className="px-1 text-lg font-semibold text-text">
                            {t('submit.participant_heading', { number: String(index + 1) })}
                            {participant.isPrimary ? ` ${t('submit.participant_primary')}` : ''}
                        </legend>
                        <p className="text-base text-text-muted">
                            {participant.email} · {t('author.edit.email_locked')}
                        </p>
                        {participantFields(index, locale, t, edit.participantFields)
                            .filter((field) => !field.key.endsWith('.email'))
                            .map((field) => (
                                <FormFieldInput
                                    key={field.key}
                                    field={field}
                                    locale={locale}
                                    defaultLocale={edit.defaultLocale}
                                    idPrefix="edit"
                                    t={t}
                                    error={mapped.fields[field.key]}
                                    initialValue={edit.initialValues[field.key]}
                                />
                            ))}
                    </fieldset>
                ))}
            </section>
            <h2 className="text-2xl font-semibold text-text">{edit.extraSectionTitle ?? t('submit.section_questions')}</h2>
        </>
    );

    const title = t('author.edit.title');

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="edit-title" className="flex flex-col gap-6">
                <header className="flex flex-col gap-2">
                    <h1 id="edit-title" className={PAGE_TITLE}>
                        {title}
                    </h1>
                    <p className="text-lg text-text">
                        {event.name} · {t('author.show.reference', { reference })}
                    </p>
                    <p className="max-w-prose text-base text-text-muted">{t('author.edit.intro')}</p>
                    <p className="max-w-prose text-base text-text-muted">{t('author.edit.files_note')}</p>
                </header>

                {editError !== undefined && (
                    <section
                        role="alert"
                        aria-labelledby="edit-error-title"
                        className="flex flex-col gap-2 rounded-lg border border-border bg-error-bg p-4"
                    >
                        <h2 id="edit-error-title" className="text-lg font-semibold text-error">
                            {t('author.edit.error_title')}
                        </h2>
                        <p className="text-base text-text">{editError}</p>
                        <button type="button" onClick={handleReload} className={`${BUTTON_SECONDARY} self-start`}>
                            {t('author.edit.reload')}
                        </button>
                    </section>
                )}
                {hasFieldErrors && (
                    <p role="alert" className="text-base text-error">
                        {t('author.edit.error_fields')}
                    </p>
                )}

                <FormRenderer
                    mode="public"
                    fields={edit.fields}
                    locale={locale}
                    defaultLocale={edit.defaultLocale}
                    t={t}
                    errors={mapped.fields}
                    onSubmit={handleSubmit}
                    leading={leading}
                    initialValues={edit.initialValues}
                    isSubmitting={isProcessing}
                    submitLabel={isProcessing ? t('author.edit.saving') : t('author.edit.save')}
                    idPrefix="edit-answers"
                />

                <div className="border-t border-border pt-4">
                    <TextLink href={showUrl} className="-ml-3">
                        {t('author.edit.cancel')}
                    </TextLink>
                </div>
            </article>
        </PublicLayout>
    );
}
