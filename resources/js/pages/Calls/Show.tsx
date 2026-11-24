import { Head } from '@inertiajs/react';

import { TextLink } from '@/components/atoms/TextLink';
import { CallStateBadge } from '@/components/molecules/CallStateBadge';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { CallLanding } from '@/components/organisms/CallLanding';
import type { CallLandingData, CallRouteSlugs } from '@/types/call-landing';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_PRIMARY, CALL_COLUMN, FACTS_PANEL, FOCUS_RING_ON_DARK } from '@/lib/classes';
import { formatEventInstant } from '@/lib/date-format';
import { cn } from '@/lib/utils';
import type { PublicCall } from '@/types/call';
import type { PublicOrganization } from '@/types/event';

interface ShowProps {
    call: PublicCall;
    event: { name: string; logoUrl?: string | null };
    organization: PublicOrganization;
    /** URL de la página del evento, con el idioma de esta página: la entrega el servidor. */
    eventUrl: string;
    /** URL del aviso de privacidad publicado, o `null` si no hay. */
    privacyUrl: string | null;
    /** URL del formulario de envío; solo existe con la convocatoria abierta. */
    submitUrl?: string | null;
    /** Rutas (organización, evento, convocatoria): permiten encontrar el texto propio de una convocatoria, si lo tiene. */
    slugs?: CallRouteSlugs;
    /** Datos con los que se arma la landing (formulario, límite de personas, otras convocatorias). */
    landing?: CallLandingData;
}

/**
 * Página pública de una convocatoria: `/{organización}/events/{evento}/call/{convocatoria}`.
 * Portada de color con el título, el estado y la descripción; con la convocatoria abierta ofrece el
 * botón grande que lleva al formulario de envío (`submitUrl`, plan 006). Muestra apertura, cierre y resultados en fichas; la categoría, la zona horaria y los ejes se
 * ocultan (los ejes siguen en el formulario de envío).
 */
export default function Show({ call, event, organization, eventUrl, privacyUrl, submitUrl = null, slugs, landing }: ShowProps) {
    const { t } = useTranslation();

    // Toda convocatoria (en español) usa la landing, armada con sus datos; en otros idiomas, la presentación de siempre.
    if (landing !== undefined && slugs !== undefined && call.locale === 'es') {
        return (
            <CallLanding
                call={call}
                event={event}
                data={landing}
                organization={organization}
                slugs={slugs}
                eventUrl={eventUrl}
                privacyUrl={privacyUrl}
                submitUrl={submitUrl}
            />
        );
    }

    const showBackLink = call.showBackLink !== false;
    const formatDate = (instant: string): string => formatEventInstant(instant, call.timezone, call.locale, call.timeFormat);

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={`${call.title} · ${event.name} · ${organization.name}`}>
                {call.description !== null && <meta name="description" content={call.description} />}
            </Head>
            <article aria-labelledby="call-title" className={CALL_COLUMN}>
                <PageBanner
                    eyebrow={event.name}
                    title={call.title}
                    titleId="call-title"
                    logoUrl={event.logoUrl}
                    logoName={event.name}
                    isRoomy
                >
                    <div className="flex flex-col items-start gap-2">
                        <CallStateBadge state={call.state} t={t} />
                        <p className="text-base text-primary-contrast">{t(`call.state_hint.${call.state}`)}</p>
                    </div>
                    {call.description !== null && (
                        <p className="max-w-prose border-l-4 border-cta pl-4 text-lg leading-relaxed text-primary-contrast sm:text-xl">
                            {call.description}
                        </p>
                    )}
                    {submitUrl !== null && (
                        <a href={submitUrl} className={cn(BUTTON_PRIMARY, FOCUS_RING_ON_DARK, 'min-h-14 w-full px-10 text-lg sm:w-auto')}>
                            {t('submit.send_action')}
                        </a>
                    )}
                </PageBanner>

                <dl className={FACTS_PANEL}>
                    <EventFact variant="tile" label={t('call.opens_at')}>
                        {formatDate(call.opensAt)}
                    </EventFact>
                    <EventFact variant="tile" label={t('call.closes_at')}>
                        {formatDate(call.closesAt)}
                    </EventFact>
                    {call.resultsAt !== undefined && (
                        <EventFact variant="tile" label={t('call.results_at')}>
                            {formatDate(call.resultsAt)}
                        </EventFact>
                    )}
                </dl>

                <div className="flex flex-col gap-2 rounded-3xl border border-border bg-surface p-5 shadow-md sm:flex-row sm:flex-wrap sm:items-center sm:gap-6 sm:p-8">
                    {showBackLink && (
                        <TextLink href={eventUrl} className="-ml-3">
                            {t('call.back_to_event')}
                        </TextLink>
                    )}
                    {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                    {(call.extraLinks ?? []).map((link, index) => (
                        <TextLink key={`${index}-${link.url}`} href={link.url} rel="noopener noreferrer nofollow">
                            {link.label}
                        </TextLink>
                    ))}
                    <LocaleSwitcher locales={call.locales} currentLocale={call.locale} />
                </div>
            </article>
        </PublicLayout>
    );
}
