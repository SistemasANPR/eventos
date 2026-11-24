import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { TextLink } from '@/components/atoms/TextLink';
import { ActivityCard } from '@/components/molecules/ActivityCard';
import { CategoryFilter } from '@/components/molecules/CategoryFilter';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { ProgramDayNav } from '@/components/molecules/ProgramDayNav';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_FOOTER, SECTION_HEADING } from '@/lib/classes';
import { formatLocalDate, formatTimeZoneOffset } from '@/lib/date-format';
import type { PublicEvent, PublicOrganization } from '@/types/event';
import type { Paginated, ProgramNav, PublicActivitySummary, PublicCategory } from '@/types/program';

interface ProgramProps {
    event: PublicEvent;
    organization: PublicOrganization;
    /** Días con actividades visibles (`AAAA-MM-DD`), en orden. Vacío: el programa aún no se publica. */
    days: string[];
    /** Día que se muestra; `null` si no hay ninguno. */
    day: string | null;
    activities: Paginated<PublicActivitySummary>;
    /** Categorías usadas en el día mostrado. */
    categories: PublicCategory[];
    nav: ProgramNav;
    /** URL del aviso de privacidad publicado, o `null` si no hay. */
    privacyUrl: string | null;
}

/**
 * Programa público del evento: `/{organización}/events/{evento}/program`. Un día por vista con
 * navegación entre días, actividades en la hora local del evento (sin convertir) y filtro por
 * categoría en el cliente. Sin actividades visibles: mensaje y `noindex`.
 * Metadatos mínimos aquí (título, descripción, `og:title`); canonical y `hreflang` llegan con F4.
 */
export default function Program({ event, organization, days, day, activities, categories, nav, privacyUrl }: ProgramProps) {
    const { t } = useTranslation();
    const [selectedCategory, setSelectedCategory] = useState<string | null>(null);

    const isEmpty = day === null;
    // Al cambiar de día las categorías son otras: una elección que ya no existe equivale a «todas».
    const activeCategory = categories.some((category) => category.id === selectedCategory) ? selectedCategory : null;
    const shown = activeCategory === null ? activities.data : activities.data.filter((activity) => activity.categories.some((category) => category.id === activeCategory));

    const title = `${t('program.title')} · ${event.name} · ${organization.name}`;
    const description = t('program.description', { event: event.name });
    const zone = formatTimeZoneOffset(event.timezone, event.locale, event.startsAt);

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title} />
                <meta property="og:description" content={description} />
                {isEmpty && <meta name="robots" content="noindex" />}
            </Head>
            <div className="flex flex-col gap-8">
                <PageBanner eyebrow={event.name} title={t('program.title')} titleId="program-title" logoUrl={event.logoUrl} logoName={event.name}>
                    <p className="text-base text-primary-contrast">{t('program.timezone_note', { zone })}</p>
                </PageBanner>

                {isEmpty ? (
                    <section aria-labelledby="program-empty" className="flex flex-col gap-2">
                        <h2 id="program-empty" className={SECTION_HEADING}>
                            {t('program.empty_title')}
                        </h2>
                        <p className="pt-2 text-base text-text-muted">{t('program.empty_hint')}</p>
                    </section>
                ) : (
                    <section aria-labelledby="program-day" className="flex flex-col gap-6">
                        <ProgramDayNav days={days} currentDay={day} baseUrl={nav.programUrl} locale={event.locale} />
                        <h2 id="program-day" className={SECTION_HEADING}>
                            {formatLocalDate(day, event.locale)}
                        </h2>
                        <CategoryFilter categories={categories} selectedId={activeCategory} onSelect={setSelectedCategory} />
                        {shown.length === 0 ? (
                            <p role="status" className="text-base text-text-muted">
                                {t('program.no_results')}
                            </p>
                        ) : (
                            <ul className="grid gap-4 lg:grid-cols-2">
                                {shown.map((activity) => (
                                    <li key={activity.id} className="flex">
                                        <ActivityCard activity={activity} locale={event.locale} timeFormat={event.timeFormat} className="w-full" />
                                    </li>
                                ))}
                            </ul>
                        )}
                        <PageLinks activities={activities} />
                    </section>
                )}

                <div className={PAGE_FOOTER}>
                    <div className="flex flex-wrap items-center gap-x-4">
                        <TextLink href={nav.eventUrl}>{t('program.back_to_event')}</TextLink>
                        {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                    </div>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </div>
        </PublicLayout>
    );
}

/** Páginas anterior y siguiente de un día con más actividades de las que caben en una página. */
function PageLinks({ activities }: { activities: Paginated<PublicActivitySummary> }) {
    const { t } = useTranslation();

    if (activities.lastPage < 2) {
        return null;
    }

    return (
        <nav aria-label={t('program.pagination_label')} className="flex flex-wrap items-center justify-between gap-2">
            {activities.prevUrl !== null ? (
                <TextLink href={activities.prevUrl} rel="prev">
                    {t('program.previous_page')}
                </TextLink>
            ) : (
                <span />
            )}
            <span className="text-base text-text-muted">{t('program.page_of', { current: String(activities.currentPage), last: String(activities.lastPage) })}</span>
            {activities.nextUrl !== null ? (
                <TextLink href={activities.nextUrl} rel="next">
                    {t('program.next_page')}
                </TextLink>
            ) : (
                <span />
            )}
        </nav>
    );
}
