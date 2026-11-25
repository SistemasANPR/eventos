import { Head } from '@inertiajs/react';

import { RichText } from '@/components/atoms/RichText';
import { TextLink } from '@/components/atoms/TextLink';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { SpeakerCard } from '@/components/molecules/SpeakerCard';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { CATEGORY_CHIP, PAGE_FOOTER, SECTION_HEADING } from '@/lib/classes';
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
 * saneada, imagen y SOLO los ponentes visibles, en el orden que fijó el organizador.
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
