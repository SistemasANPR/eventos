import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { TextLink } from '@/components/atoms/TextLink';
import { EventFact } from '@/components/molecules/EventFact';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_PRIMARY, BUTTON_SECONDARY, FACTS_PANEL, PAGE_TITLE } from '@/lib/classes';
import { formatPublishedAt } from '@/lib/date-format';
import type { AuthorAnswer, AuthorParticipant, AuthorSubmission, SubmissionStatus } from '@/types/author';
import type { PublicOrganization } from '@/types/event';

interface ShowProps {
    event: { name: string };
    organization: PublicOrganization;
    submission: AuthorSubmission;
    /** Minutos que dura la sesión del autor. */
    sessionMinutes: number;
    eventUrl: string | null;
    /** Destino del POST que retira la propuesta, o `null` si ya no se puede retirar (la decide el servidor). */
    withdrawUrl: string | null;
    /** Pantalla de edición, o `null` si la propuesta ya no se puede editar (la decide el servidor). */
    editUrl: string | null;
    /** Se acaba de guardar una edición. */
    wasUpdated: boolean;
}

/** Marca y colores de cada estado (par texto/fondo de los semánticos): el estado no depende solo del color. */
const STATUS_STYLE: Record<SubmissionStatus, { classes: string; mark: string }> = {
    submitted: { classes: 'bg-info-bg text-info', mark: '●' },
    under_review: { classes: 'bg-info-bg text-info', mark: '◷' },
    accepted: { classes: 'bg-success-bg text-success', mark: '✔' },
    confirmed: { classes: 'bg-success-bg text-success', mark: '✔' },
    waitlisted: { classes: 'bg-warning-bg text-warning', mark: '❚❚' },
    rejected: { classes: 'bg-error-bg text-error', mark: '■' },
    withdrawn: { classes: 'bg-warning-bg text-warning', mark: '■' },
};

/**
 * La propuesta del autor (con sesión de autor): `/{organización}/events/{evento}/my-submission`. Solo lo que
 * el autor envió —título, eje, estado, respuestas y participantes—; nada de lo que es del equipo organizador.
 * Los textos de las respuestas se muestran escapados (React), nunca como HTML.
 */
export default function Show({ event, organization, submission, sessionMinutes, eventUrl, withdrawUrl, editUrl, wasUpdated }: ShowProps) {
    const { t, locale } = useTranslation();
    const { props } = usePage<{ errors?: Record<string, string> }>();
    const editError = props.errors?.edit;
    const style = STATUS_STYLE[submission.status];
    const title = t('author.show.title');

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${submission.title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="submission-title" className="flex flex-col gap-8">
                <header className="flex flex-col gap-3">
                    <p className="text-base font-medium text-text-muted">
                        {title} · {event.name}
                    </p>
                    <h1 id="submission-title" className={PAGE_TITLE}>
                        {submission.title}
                    </h1>
                    <p className="text-base text-text-muted">{t('author.show.reference', { reference: submission.reference })}</p>
                    <span
                        data-testid="submission-status"
                        className={`inline-flex items-center gap-2 self-start rounded-full px-3 py-1 text-sm font-semibold ${style.classes}`}
                    >
                        <span aria-hidden="true">{style.mark}</span>
                        <span className="sr-only">{t('author.show.status_label')}: </span>
                        {t(`author.show.status.${submission.status}`)}
                    </span>
                    {submission.withdrawnAt !== null && (
                        <p role="status" className="max-w-prose text-base text-text">
                            {t('author.show.withdrawn_notice', { date: formatPublishedAt(submission.withdrawnAt, locale) })}
                        </p>
                    )}
                </header>

                {wasUpdated && (
                    <p role="status" className="max-w-prose rounded-lg border border-border bg-success-bg p-4 text-base text-success">
                        {t('author.show.updated_notice')}
                    </p>
                )}
                {editError !== undefined && (
                    <p role="alert" className="max-w-prose text-base text-error">
                        {editError}
                    </p>
                )}
                {editUrl !== null && (
                    <TextLink href={editUrl} className={`${BUTTON_SECONDARY} self-start no-underline`}>
                        {t('author.show.edit_action')}
                    </TextLink>
                )}

                <dl className={FACTS_PANEL}>
                    <EventFact label={t('author.show.track')}>{submission.track}</EventFact>
                    <EventFact label={t('author.show.submitted_on')}>{formatPublishedAt(submission.submittedAt, locale)}</EventFact>
                </dl>

                <section aria-labelledby="answers-title" className="flex flex-col gap-3">
                    <h2 id="answers-title" className="text-2xl font-semibold text-text">
                        {t('author.show.answers')}
                    </h2>
                    {submission.answers.length === 0 ? (
                        <p className="text-base text-text-muted">{t('author.show.no_answers')}</p>
                    ) : (
                        <dl className="flex flex-col gap-4">
                            {submission.answers.map((answer) => (
                                <AnswerRow key={answer.key} answer={answer} />
                            ))}
                        </dl>
                    )}
                </section>

                <section aria-labelledby="participants-title" className="flex flex-col gap-3">
                    <h2 id="participants-title" className="text-2xl font-semibold text-text">
                        {t('author.show.participants')}
                    </h2>
                    <ul className="flex flex-col gap-4">
                        {submission.participants.map((participant) => (
                            <ParticipantItem key={participant.email} participant={participant} />
                        ))}
                    </ul>
                </section>

                {withdrawUrl !== null && <WithdrawSection withdrawUrl={withdrawUrl} />}

                <footer className="flex flex-col gap-2 border-t border-border pt-4">
                    <p className="text-sm text-text-muted">{t('author.show.session_notice', { minutes: String(sessionMinutes) })}</p>
                    {eventUrl !== null && (
                        <TextLink href={eventUrl} className="-ml-3 self-start">
                            {t('author.show.back_to_event')}
                        </TextLink>
                    )}
                </footer>
            </article>
        </PublicLayout>
    );
}

/**
 * Retirar la propuesta en dos pasos (un clic no basta): primero se pide confirmar y solo el segundo botón envía el
 * POST. El servidor vuelve a comprobar que se puede retirar y es idempotente.
 */
function WithdrawSection({ withdrawUrl }: { withdrawUrl: string }) {
    const { t } = useTranslation();
    const { props } = usePage<{ errors?: Record<string, string> }>();
    const [isConfirming, setIsConfirming] = useState(false);
    const [isWithdrawing, setIsWithdrawing] = useState(false);
    const error = props.errors?.withdraw;

    const handleWithdraw = () => {
        router.post(withdrawUrl, {}, { onStart: () => setIsWithdrawing(true), onFinish: () => setIsWithdrawing(false) });
    };

    return (
        <section
            aria-labelledby="withdraw-title"
            className="flex max-w-prose flex-col gap-3 rounded-lg border border-border bg-surface p-4"
        >
            <h2 id="withdraw-title" className="text-xl font-semibold text-text">
                {t('author.show.withdraw.action')}
            </h2>
            {error !== undefined && (
                <p role="alert" className="text-base text-error">
                    {error}
                </p>
            )}
            {isConfirming ? (
                <div role="group" aria-labelledby="withdraw-confirm-title" className="flex flex-col gap-3">
                    <p id="withdraw-confirm-title" className="text-base font-medium text-text">
                        {t('author.show.withdraw.confirm_title')}
                    </p>
                    <p className="text-base text-text-muted">{t('author.show.withdraw.confirm_body')}</p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button type="button" onClick={handleWithdraw} disabled={isWithdrawing} className={BUTTON_PRIMARY}>
                            {isWithdrawing ? t('author.show.withdraw.withdrawing') : t('author.show.withdraw.confirm_action')}
                        </button>
                        <button type="button" onClick={() => setIsConfirming(false)} disabled={isWithdrawing} className={BUTTON_SECONDARY}>
                            {t('author.show.withdraw.cancel')}
                        </button>
                    </div>
                </div>
            ) : (
                <button type="button" onClick={() => setIsConfirming(true)} className={`${BUTTON_SECONDARY} self-start`}>
                    {t('author.show.withdraw.action')}
                </button>
            )}
        </section>
    );
}

function AnswerRow({ answer }: { answer: AuthorAnswer }) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-1">
            <dt className="text-base font-medium text-text-muted">{answer.label}</dt>
            <dd className="max-w-prose whitespace-pre-line break-words text-base text-text">{answerText(answer, t)}</dd>
        </div>
    );
}

function answerText(answer: AuthorAnswer, t: ReturnType<typeof useTranslation>['t']): string {
    const { value } = answer;

    if (value === null || value === '') {
        return t('author.show.no_value');
    }

    if (typeof value === 'boolean') {
        return value ? t('author.show.yes') : t('author.show.no');
    }

    if (Array.isArray(value)) {
        return value.length === 0 ? t('author.show.no_value') : value.join(', ');
    }

    return answer.type === 'file' ? t('author.show.files_count', { count: value }) : value;
}

function ParticipantItem({ participant }: { participant: AuthorParticipant }) {
    const { t } = useTranslation();

    return (
        <li className="flex flex-col gap-1 rounded-lg border border-border bg-surface p-4">
            <p className="text-lg font-semibold text-text">
                {participant.fullName}
                {participant.isPrimary && (
                    <span className="ml-2 rounded-full bg-info-bg px-2 py-0.5 text-sm font-semibold text-info">
                        {t('author.show.primary')}
                    </span>
                )}
            </p>
            <p className="break-words text-base text-text-muted">
                {[participant.email, participant.country, participant.jobTitle, participant.company]
                    .filter((part): part is string => part !== null && part !== '')
                    .join(' · ')}
            </p>
            {participant.bio !== null && (
                <p className="max-w-prose whitespace-pre-line break-words text-base text-text">{participant.bio}</p>
            )}
        </li>
    );
}
