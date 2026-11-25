import { Head } from '@inertiajs/react';

import { RichText } from '@/components/atoms/RichText';
import { TextLink } from '@/components/atoms/TextLink';
import { ActivityCard } from '@/components/molecules/ActivityCard';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { CATEGORY_CHIP, FOCUS_RING, PAGE_FOOTER, SECTION_HEADING } from '@/lib/classes';
import { cn } from '@/lib/utils';
import type { PublicEvent, PublicOrganization } from '@/types/event';
import type { ProgramNav, PublicActivitySummary, PublicSpeaker } from '@/types/program';

interface SpeakerProps {
    event: PublicEvent;
    organization: PublicOrganization;
    speaker: PublicSpeaker;
    /** Solo las actividades visibles del ponente, en orden de día y hora; vacío si aún no tiene. */
    activities: PublicActivitySummary[];
    nav: ProgramNav;
}

/**
 * Ficha pública de un ponente: `/{organización}/events/{evento}/speakers/{id}`. Nombre, cargo y
 * organización, fotografía, biografía saneada, categorías, redes `https` y sus actividades visibles
 * (o el aviso de que aún no tiene). La biografía va por `RichText`; los enlaces externos no pasan
 * referencia ni la dan a buscadores.
 */
export default function Speaker({ event, organization, speaker, activities, nav }: SpeakerProps) {
    const { t } = useTranslation();

    const role = [speaker.jobTitle, speaker.company].filter((part): part is string => part !== null).join(t('speakers.role_separator'));
    const title = `${speaker.name} · ${event.name} · ${organization.name}`;
    const description = speaker.tagline ?? t('speaker.description', { name: speaker.name, event: event.name });

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title} />
                <meta property="og:description" content={description} />
            </Head>
            <div className="flex flex-col gap-8">
                <PageBanner eyebrow={event.name} title={speaker.name} titleId="speaker-title" logoUrl={event.logoUrl} logoName={event.name}>
                    {role !== '' && <p className="text-lg text-primary-contrast">{role}</p>}
                    {speaker.tagline !== null && <p className="text-base text-primary-contrast">{speaker.tagline}</p>}
                </PageBanner>

                <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
                    {speaker.photoUrl !== null && (
                        <img src={speaker.photoUrl} alt={speaker.name} width={192} height={192} loading="lazy" className="h-48 w-48 shrink-0 rounded-2xl object-cover" />
                    )}
                    <div className="flex min-w-0 flex-col gap-6">
                        {speaker.categories.length > 0 && (
                            <ul aria-label={t('speaker.categories')} className="flex flex-wrap gap-2">
                                {speaker.categories.map((category) => (
                                    <li key={category} className={CATEGORY_CHIP}>
                                        {category}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {speaker.bioHtml !== null && (
                            <section aria-labelledby="speaker-bio" className="flex flex-col gap-2">
                                <h2 id="speaker-bio" className={SECTION_HEADING}>
                                    {t('speaker.bio')}
                                </h2>
                                <RichText html={speaker.bioHtml} className="pt-2" />
                            </section>
                        )}
                        {speaker.socialLinks.length > 0 && (
                            <section aria-labelledby="speaker-links" className="flex flex-col gap-2">
                                <h2 id="speaker-links" className={SECTION_HEADING}>
                                    {t('speaker.links')}
                                </h2>
                                <ul className="flex flex-wrap gap-x-4 pt-2">
                                    {speaker.socialLinks.map((link) => (
                                        <li key={link.network}>
                                            <a
                                                href={link.url}
                                                rel="noopener noreferrer nofollow"
                                                className={cn('inline-flex min-h-11 items-center rounded-md text-base text-primary underline underline-offset-4', FOCUS_RING)}
                                            >
                                                {networkName(link.network, t)}
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </div>
                </div>

                <section aria-labelledby="speaker-activities" className="flex flex-col gap-6">
                    <h2 id="speaker-activities" className={SECTION_HEADING}>
                        {t('speaker.activities')}
                    </h2>
                    {activities.length === 0 ? (
                        <p className="text-base text-text-muted">{t('speaker.no_activities')}</p>
                    ) : (
                        <ul className="grid gap-4 lg:grid-cols-2">
                            {activities.map((activity) => (
                                <li key={activity.id} className="flex">
                                    <ActivityCard activity={activity} locale={event.locale} timeFormat={event.timeFormat} className="w-full" />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <div className={PAGE_FOOTER}>
                    <div className="flex flex-wrap items-center gap-x-4">
                        <TextLink href={nav.speakersUrl}>{t('speaker.back_to_speakers')}</TextLink>
                        <TextLink href={nav.programUrl}>{t('speakers.program_link')}</TextLink>
                    </div>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </div>
        </PublicLayout>
    );
}

/** Nombre de una red con su traducción; una red que la interfaz no conoce se muestra tal cual. */
function networkName(network: string, t: (key: string) => string): string {
    const key = `speaker.networks.${network}`;
    const name = t(key);

    return name === key ? network : name;
}
