import { TextLink } from '@/components/atoms/TextLink';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { useTranslation } from '@/hooks/useTranslation';
import { FACTS_PANEL } from '@/lib/classes';
import { formatEventDates } from '@/lib/date-format';
import { cn } from '@/lib/utils';
import type { PublicEvent } from '@/types/event';

interface EventHeroProps {
    event: PublicEvent;
    /** URL del aviso de privacidad publicado, o `null` si el evento no tiene uno. */
    privacyUrl: string | null;
}

/**
 * Portada de un evento: bloque violeta con el logotipo, el nombre y la descripción, y debajo,
 * montadas sobre su borde, las tarjetas con fechas en su zona horaria y sede.
 * Usar en: página pública del evento (`pages/Events/Show`).
 */
export function EventHero({ event, privacyUrl }: EventHeroProps) {
    const { t } = useTranslation();

    const dates = formatEventDates(event.startsAt, event.endsAt, event.timezone, event.locale, event.timeFormat);
    const venue = [event.venueName, event.city].filter(Boolean).join(', ');

    return (
        <section aria-labelledby="event-title" className="flex flex-col gap-6">
            <div className="flex flex-col">
                <PageBanner title={event.name} titleId="event-title" logoUrl={event.logoUrl} logoName={event.name} hasOverlap>
                    {event.description !== null && <p className="max-w-prose text-lg text-primary-contrast">{event.description}</p>}
                </PageBanner>

                <dl className={cn(FACTS_PANEL, 'relative -mt-12 px-4 sm:px-8')}>
                    <EventFact label={t('event.when')}>{dates}</EventFact>
                    <EventFact label={t('event.where')}>{venue === '' ? t('event.no_venue') : venue}</EventFact>
                    <EventFact label={t('event.timezone')}>{event.timezone}</EventFact>
                </dl>
            </div>

            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6">
                {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
            </div>
        </section>
    );
}
