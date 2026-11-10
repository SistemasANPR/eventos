import { Head } from '@inertiajs/react';

import { EventHero } from '@/components/organisms/EventHero';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { PublicEvent, PublicOrganization } from '@/types/event';

interface ShowProps {
    event: PublicEvent;
    organization: PublicOrganization;
}

/** Página pública de un evento publicado: `/{organización}/events/{evento}`. */
export default function Show({ event, organization }: ShowProps) {
    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${event.name} · ${organization.name}`}>
                {event.description !== null && <meta name="description" content={event.description} />}
            </Head>
            <EventHero event={event} />
        </PublicLayout>
    );
}
