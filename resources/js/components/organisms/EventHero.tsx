import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';
import { formatEventDates } from '@/lib/date-format';
import type { PublicEvent } from '@/types/event';

interface EventHeroProps {
    event: PublicEvent;
}

/**
 * Presentación de un evento: nombre, descripción, fechas en su zona horaria y sede.
 * Usar en: página pública del evento (`pages/Events/Show`).
 */
export function EventHero({ event }: EventHeroProps) {
    const { t } = useTranslation();

    const dates = formatEventDates(event.startsAt, event.endsAt, event.timezone, event.locale);
    const venue = [event.venueName, event.city].filter(Boolean).join(', ');

    return (
        <section aria-labelledby="event-title" className="flex flex-col gap-6">
            <div className="flex flex-col gap-3">
                <h1 id="event-title" className="text-3xl font-bold tracking-tight text-text sm:text-4xl">
                    {event.name}
                </h1>
                {event.description !== null && (
                    <p className="max-w-prose text-lg text-text-muted">{event.description}</p>
                )}
            </div>

            <dl className="grid gap-4 rounded-lg border border-border bg-surface p-4 sm:grid-cols-2 sm:p-6">
                <EventFact label={t('event.when')}>{dates}</EventFact>
                <EventFact label={t('event.where')}>{venue === '' ? t('event.no_venue') : venue}</EventFact>
                <EventFact label={t('event.timezone')}>{event.timezone}</EventFact>
            </dl>

            <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
        </section>
    );
}
