import { TextLink } from '@/components/atoms/TextLink';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';
import { FACTS_PANEL, PAGE_TITLE } from '@/lib/classes';
import { formatEventDates } from '@/lib/date-format';
import type { PublicEvent } from '@/types/event';

interface EventHeroProps {
    event: PublicEvent;
    /** URL del aviso de privacidad publicado, o `null` si el evento no tiene uno. */
    privacyUrl: string | null;
}

/**
 * Presentación de un evento: nombre, descripción, fechas en su zona horaria y sede.
 * Usar en: página pública del evento (`pages/Events/Show`).
 */
export function EventHero({ event, privacyUrl }: EventHeroProps) {
    const { t } = useTranslation();

    const dates = formatEventDates(event.startsAt, event.endsAt, event.timezone, event.locale);
    const venue = [event.venueName, event.city].filter(Boolean).join(', ');

    return (
        <section aria-labelledby="event-title" className="flex flex-col gap-6">
            <div className="flex flex-col gap-3">
                <h1 id="event-title" className={PAGE_TITLE}>
                    {event.name}
                </h1>
                {event.description !== null && (
                    <p className="max-w-prose text-lg text-text-muted">{event.description}</p>
                )}
            </div>

            <dl className={FACTS_PANEL}>
                <EventFact label={t('event.when')}>{dates}</EventFact>
                <EventFact label={t('event.where')}>{venue === '' ? t('event.no_venue') : venue}</EventFact>
                <EventFact label={t('event.timezone')}>{event.timezone}</EventFact>
            </dl>

            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-6">
                {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
            </div>
        </section>
    );
}
