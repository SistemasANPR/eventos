import { Head } from '@inertiajs/react';

import { RichText } from '@/components/atoms/RichText';
import { TextLink } from '@/components/atoms/TextLink';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { SpeakerCard } from '@/components/molecules/SpeakerCard';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { CATEGORY_CHIP, HEADING_MD, PAGE_FOOTER, SECTION_HEADING } from '@/lib/classes';
import { formatLocalDate, formatLocalTime } from '@/lib/date-format';
import type { PublicEvent, PublicOrganization } from '@/types/event';
import type { ProgramNav, PublicActivity } from '@/types/program';

interface ActivityProps {
    event: PublicEvent;
    organization: PublicOrganization;
    activity: PublicActivity;
    nav: ProgramNav;
}

/**
 * Detalle público de una actividad: `/{organización}/events/{evento}/activities/{id}`. Título, fecha y
 * horas locales del evento (sin convertir) con su formato de 12 h o 24 h, categorías, descripción
 * saneada, imagen y SOLO los ponentes visibles, en el orden que fijó el organizador. Plan 035: si hay datos,
 * el cupo (un número de personas, sin inscripción), el lugar con su dirección y mapa, el punto de reunión, el
 * transporte y los responsables que el organizador hizo públicos; los textos van como texto plano (React los escapa).
 */
export default function Activity({ event, organization, activity, nav }: ActivityProps) {
    const { t } = useTranslation();

    const range = t('program.time_range', {
        start: formatLocalTime(activity.startTime, event.locale, event.timeFormat),
        end: formatLocalTime(activity.endTime, event.locale, event.timeFormat),
    });
    const endsAnotherDay = activity.endDate !== activity.date;
    const title = `${activity.title} · ${event.name} · ${organization.name}`;
    const description = activity.summary ?? t('activity.description', { title: activity.title, event: event.name });

    // Cada bloque solo existe si hay dato: la página no pinta etiquetas vacías (plan 035, CA-035-25 a 28).
    const hasDetails = activity.seats !== null || activity.place !== null || activity.meetingPoint !== null || activity.transport !== null;
    // Defensa en profundidad: el servidor ya solo manda `https`, pero un enlace que no lo sea nunca se pinta.
    const mapUrl = activity.place?.mapUrl?.startsWith('https://') ? activity.place.mapUrl : null;
    const people = (count: number): string => {
        const form = new Intl.PluralRules(event.locale).select(count) === 'one' ? 'one' : 'other';

        return t(`activity.seats_value.${form}`, { count: new Intl.NumberFormat(event.locale).format(count) });
    };

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title} />
                <meta property="og:description" content={description} />
            </Head>
            <div className="flex flex-col gap-8">
                <PageBanner eyebrow={event.name} title={activity.title} titleId="activity-title" logoUrl={event.logoUrl} logoName={event.name}>
                    <p className="text-lg font-semibold text-primary-contrast">{t('activity.on_date', { date: formatLocalDate(activity.date, event.locale), range })}</p>
                    {endsAnotherDay && <p className="text-base text-primary-contrast">{t('program.until', { date: formatLocalDate(activity.endDate, event.locale) })}</p>}
                    {activity.summary !== null && <p className="text-base text-primary-contrast">{activity.summary}</p>}
                </PageBanner>

                {activity.categories.length > 0 && (
                    <ul aria-label={t('activity.categories')} className="flex flex-wrap gap-2">
                        {activity.categories.map((category) => (
                            <li key={category.id} className={CATEGORY_CHIP}>
                                {category.name}
                            </li>
                        ))}
                    </ul>
                )}

                {hasDetails && (
                    <section aria-labelledby="activity-details" className="flex flex-col gap-4">
                        <h2 id="activity-details" className={SECTION_HEADING}>
                            {t('activity.details_heading')}
                        </h2>
                        <dl className="grid gap-4 sm:grid-cols-2">
                            {activity.seats !== null && <EventFact label={t('activity.seats')}>{people(activity.seats)}</EventFact>}
                            {activity.place !== null && (
                                <EventFact label={t('activity.place')}>
                                    {activity.place.name}
                                    {activity.place.address !== null && <span className="block text-base font-normal text-text-muted">{activity.place.address}</span>}
                                    {mapUrl !== null && (
                                        <TextLink
                                            href={mapUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label={t('activity.map_link_label', { place: activity.place.name })}
                                            className="-ml-3 text-base font-normal"
                                        >
                                            {t('activity.map_link')}
                                        </TextLink>
                                    )}
                                </EventFact>
                            )}
                            {activity.place?.capacity != null && <EventFact label={t('activity.capacity')}>{people(activity.place.capacity)}</EventFact>}
                            {activity.meetingPoint !== null && <EventFact label={t('activity.meeting_point')}>{activity.meetingPoint}</EventFact>}
                            {activity.transport !== null && <EventFact label={t('activity.transport')}>{activity.transport}</EventFact>}
                        </dl>
                    </section>
                )}

                {activity.contacts.length > 0 && (
                    <section aria-labelledby="activity-contacts" className="flex flex-col gap-4">
                        <h2 id="activity-contacts" className={SECTION_HEADING}>
                            {t('activity.contacts')}
                        </h2>
                        <ul className="grid gap-4 sm:grid-cols-2">
                            {activity.contacts.map((contact, index) => (
                                <li key={`${index}-${contact.name}`} className="flex flex-col gap-1 rounded-2xl border border-border bg-surface p-5 shadow-md">
                                    <h3 className={HEADING_MD}>{contact.name}</h3>
                                    {contact.phone !== null && (
                                        <TextLink href={`tel:${contact.phone.replace(/[^\d+]/g, '')}`} className="-ml-3 self-start">
                                            {contact.phone}
                                        </TextLink>
                                    )}
                                    {contact.email !== null && (
                                        <TextLink href={`mailto:${contact.email}`} className="-ml-3 self-start">
                                            {contact.email}
                                        </TextLink>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {(activity.imageUrl !== null || activity.descriptionHtml !== null) && (
                    <section aria-labelledby="activity-description" className="flex flex-col gap-4">
                        <h2 id="activity-description" className={SECTION_HEADING}>
                            {t('activity.description_heading')}
                        </h2>
                        {activity.imageUrl !== null && (
                            // El título ya está en el encabezado: la imagen es decorativa para el lector de pantalla.
                            <img src={activity.imageUrl} alt="" width={1024} height={576} loading="lazy" className="h-auto max-h-96 w-full rounded-2xl object-cover" />
                        )}
                        {activity.descriptionHtml !== null && <RichText html={activity.descriptionHtml} />}
                    </section>
                )}

                <section aria-labelledby="activity-speakers" className="flex flex-col gap-6">
                    <h2 id="activity-speakers" className={SECTION_HEADING}>
                        {t('activity.speakers')}
                    </h2>
                    {activity.speakers.length === 0 ? (
                        <p className="text-base text-text-muted">{t('activity.no_speakers')}</p>
                    ) : (
                        <ul className="grid gap-4 lg:grid-cols-2">
                            {activity.speakers.map((speaker) => (
                                <li key={speaker.id} className="flex">
                                    <SpeakerCard speaker={speaker} className="w-full" />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className={PAGE_FOOTER}>
                    <div className="flex flex-wrap items-center gap-x-4">
                        <TextLink href={nav.programUrl}>{t('activity.back_to_program')}</TextLink>
                        <TextLink href={nav.speakersUrl}>{t('speakers.title')}</TextLink>
                    </div>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </div>
        </PublicLayout>
    );
}
