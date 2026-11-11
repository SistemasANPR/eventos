import { EventHero } from '@/components/organisms/EventHero';
import { useTranslation } from '@/hooks/useTranslation';
import type { PublicEvent } from '@/types/event';

/** Demo de `EventHero` con un evento ficticio. */
export function EventHeroDemo() {
    const { t, locale } = useTranslation();

    const event: PublicEvent = {
        name: t('catalog.demo_data.event_name'),
        description: t('catalog.demo_data.event_description'),
        startsAt: '2027-03-10T15:00:00Z',
        endsAt: '2027-03-12T23:00:00Z',
        timezone: 'America/Mexico_City',
        venueName: t('catalog.demo_data.event_venue'),
        venueAddress: null,
        city: t('catalog.demo_data.event_city'),
        locale,
        locales: ['es', 'en'],
    };

    return <EventHero event={event} privacyUrl="#" />;
}
