import { useState } from 'react';

import { ActivityCard } from '@/components/molecules/ActivityCard';
import { CategoryFilter } from '@/components/molecules/CategoryFilter';
import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageNav } from '@/components/molecules/PageNav';
import { ProgramDayNav } from '@/components/molecules/ProgramDayNav';
import { SpeakerCard } from '@/components/molecules/SpeakerCard';
import { useTranslation } from '@/hooks/useTranslation';

/** Demo de `EventFact`: se usa dentro de un `<dl>`. */
export function EventFactDemo() {
    const { t } = useTranslation();

    return (
        <dl>
            <EventFact label={t('catalog.demo_data.fact_label')}>{t('catalog.demo_data.fact_value')}</EventFact>
        </dl>
    );
}

/** Demo de `LocaleSwitcher`: una página disponible en tres idiomas. */
export function LocaleSwitcherDemo() {
    const { locale } = useTranslation();

    return <LocaleSwitcher locales={['es', 'en', 'pt']} currentLocale={locale} />;
}

/** Demo de `ProgramDayNav`: tres días con el segundo como día actual. */
export function ProgramDayNavDemo() {
    const { locale } = useTranslation();

    return <ProgramDayNav days={['2027-03-10', '2027-03-11', '2027-03-12']} currentDay="2027-03-11" baseUrl="/components" locale={locale} />;
}

/** Demo de `ActivityCard`: una actividad de la tarde de un evento con horas de 12 h. */
export function ActivityCardDemo() {
    const { t, locale } = useTranslation();

    return (
        <ActivityCard
            locale={locale}
            timeFormat="12h"
            activity={{
                id: 'demo-activity',
                title: t('catalog.demo_data.activity_title'),
                summary: t('catalog.demo_data.activity_summary'),
                date: '2027-03-10',
                endDate: '2027-03-10',
                startTime: '15:30',
                endTime: '17:00',
                categories: [{ id: 'demo-category', name: t('catalog.demo_data.category_name') }],
                speakers: [{ id: 'demo-speaker', name: t('catalog.demo_data.speaker_name'), company: null, jobTitle: null, tagline: null, photoUrl: null, url: '/components' }],
                iconUrl: null,
                url: '/components',
            }}
        />
    );
}

/** Demo de `SpeakerCard`: una ponente con cargo, organización y descripción, sin fotografía. */
export function SpeakerCardDemo() {
    const { t } = useTranslation();

    return (
        <SpeakerCard
            speaker={{
                id: 'demo-speaker',
                name: t('catalog.demo_data.speaker_name'),
                company: t('catalog.demo_data.speaker_company'),
                jobTitle: t('catalog.demo_data.speaker_job_title'),
                tagline: t('catalog.demo_data.speaker_tagline'),
                photoUrl: null,
                url: '/components',
            }}
        />
    );
}

/** Demo de `PageNav`: la segunda de tres páginas. */
export function PageNavDemo() {
    const { t } = useTranslation();

    return <PageNav label={t('speakers.pagination_label')} page={{ currentPage: 2, lastPage: 3, prevUrl: '/components', nextUrl: '/components' }} />;
}

/** Demo de `CategoryFilter`: dos categorías con alternancia local. */
export function CategoryFilterDemo() {
    const { t } = useTranslation();
    const [selectedId, setSelectedId] = useState<string | null>(null);

    return (
        <CategoryFilter
            categories={[
                { id: 'demo-workshops', name: t('catalog.demo_data.category_name') },
                { id: 'demo-talks', name: t('catalog.demo_data.category_other') },
            ]}
            selectedId={selectedId}
            onSelect={setSelectedId}
        />
    );
}
