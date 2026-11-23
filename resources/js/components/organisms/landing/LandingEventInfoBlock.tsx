import { useId } from 'react';

import { EventFact } from '@/components/molecules/EventFact';
import { FACTS_PANEL, PAGE_TITLE, SECTION_HEADING } from '@/lib/classes';
import { formatEventDates } from '@/lib/date-format';
import type { Translate } from '@/lib/translate';
import type { PublicEventInfoBlock } from '@/types/landing';

interface LandingEventInfoBlockProps {
    block: PublicEventInfoBlock;
    locale: string;
    t: Translate;
    /** `true` si el nombre del evento es el encabezado principal (h1): no hay portada. */
    isMainHeading: boolean;
}

/** Datos del evento (nombre, fechas en su zona, sede). Usar en: `LandingRenderer`. */
export function LandingEventInfoBlock({ block, locale, t, isMainHeading }: LandingEventInfoBlockProps) {
    const headingId = useId();
    const Heading = isMainHeading ? 'h1' : 'h2';
    const dates = formatEventDates(block.startsAt, block.endsAt, block.timezone, locale, block.timeFormat);
    const venue = [block.venueName, block.city].filter(Boolean).join(', ');

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4">
            <Heading id={headingId} className={isMainHeading ? PAGE_TITLE : SECTION_HEADING}>
                {block.name}
            </Heading>
            {block.description !== null && <p className="max-w-prose text-lg text-text-muted">{block.description}</p>}
            <dl className={FACTS_PANEL}>
                <EventFact label={t('event.when')}>{dates}</EventFact>
                <EventFact label={t('event.where')}>{venue === '' ? t('event.no_venue') : venue}</EventFact>
            </dl>
        </section>
    );
}
