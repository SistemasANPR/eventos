import { Head } from '@inertiajs/react';

import { TextLink } from '@/components/atoms/TextLink';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { FACTS_PANEL, PAGE_TITLE } from '@/lib/classes';
import { formatEventInstant } from '@/lib/date-format';
import type { CallState, PublicCall } from '@/types/call';
import type { PublicOrganization } from '@/types/event';

interface ShowProps {
    call: PublicCall;
    event: { name: string };
    organization: PublicOrganization;
    /** URL de la página del evento, con el idioma de esta página: la entrega el servidor. */
    eventUrl: string;
    /** URL del aviso de privacidad publicado, o `null` si no hay. */
    privacyUrl: string | null;
}

/** Colores (par texto/fondo de los semánticos) y marca de cada estado: el estado no depende solo del color. */
const STATE_STYLE: Record<CallState, { classes: string; mark: string }> = {
    scheduled: { classes: 'bg-info-bg text-info', mark: '◷' },
    open: { classes: 'bg-success-bg text-success', mark: '●' },
    paused: { classes: 'bg-warning-bg text-warning', mark: '❚❚' },
    closed: { classes: 'bg-error-bg text-error', mark: '■' },
};

/**
 * Página pública de una convocatoria: `/{organización}/events/{evento}/call/{convocatoria}`.
 * Sin botón de envío: el formulario llega con los planes 005 y 006; hasta entonces
 * la página solo informa el estado, las fechas y los ejes.
 */
export default function Show({ call, event, organization, eventUrl, privacyUrl }: ShowProps) {
    const { t } = useTranslation();

    const style = STATE_STYLE[call.state];
    const formatDate = (instant: string): string => formatEventInstant(instant, call.timezone, call.locale);

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${call.title} · ${event.name} · ${organization.name}`}>
                {call.description !== null && <meta name="description" content={call.description} />}
            </Head>
            <article aria-labelledby="call-title" className="flex flex-col gap-8">
                <header className="flex flex-col gap-3">
                    <p className="text-base font-medium text-text-muted">{event.name}</p>
                    <h1 id="call-title" className={PAGE_TITLE}>
                        {call.title}
                    </h1>
                    <div className="flex flex-col items-start gap-2">
                        <span
                            data-testid="call-state"
                            className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ${style.classes}`}
                        >
                            <span aria-hidden="true">{style.mark}</span>
                            {t(`call.state.${call.state}`)}
                        </span>
                        <p className="text-base text-text-muted">{t(`call.state_hint.${call.state}`)}</p>
                    </div>
                    {call.description !== null && <p className="max-w-prose text-lg text-text">{call.description}</p>}
                </header>

                <dl className={FACTS_PANEL}>
                    <EventFact label={t('call.type')}>{call.submissionType}</EventFact>
                    <EventFact label={t('call.timezone')}>{call.timezone}</EventFact>
                    <EventFact label={t('call.opens_at')}>{formatDate(call.opensAt)}</EventFact>
                    <EventFact label={t('call.closes_at')}>{formatDate(call.closesAt)}</EventFact>
                    {call.resultsAt !== undefined && (
                        <EventFact label={t('call.results_at')}>{formatDate(call.resultsAt)}</EventFact>
                    )}
                </dl>

                <section aria-labelledby="call-tracks" className="flex flex-col gap-3">
                    <h2 id="call-tracks" className="text-2xl font-semibold text-text">
                        {t('call.tracks')}
                    </h2>
                    {call.tracks.length === 0 ? (
                        <p className="text-base text-text-muted">{t('call.no_tracks')}</p>
                    ) : (
                        <ul aria-labelledby="call-tracks" className="flex flex-col gap-3">
                            {call.tracks.map((track, index) => (
                                <li key={`${index}-${track.name}`} className="rounded-lg border border-border bg-surface p-4">
                                    <p className="text-lg font-semibold text-text">{track.name}</p>
                                    {track.description !== null && (
                                        <p className="mt-1 max-w-prose text-base text-text-muted">{track.description}</p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6">
                    <TextLink href={eventUrl} className="-ml-3">
                        {t('call.back_to_event')}
                    </TextLink>
                    {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                    <LocaleSwitcher locales={call.locales} currentLocale={call.locale} />
                </div>
            </article>
        </PublicLayout>
    );
}
