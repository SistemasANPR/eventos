import { Head } from '@inertiajs/react';

import { TextLink } from '@/components/atoms/TextLink';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { PageBanner } from '@/components/molecules/PageBanner';
import { PageNav } from '@/components/molecules/PageNav';
import { SpeakerCard } from '@/components/molecules/SpeakerCard';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_FOOTER, SECTION_HEADING } from '@/lib/classes';
import type { PublicEvent, PublicOrganization } from '@/types/event';
import type { Paginated, ProgramNav, PublicSpeakerSummary } from '@/types/program';

interface SpeakersProps {
    event: PublicEvent;
    organization: PublicOrganization;
    /** Una página de ponentes publicados; vacía si el evento aún no anuncia ninguno. */
    speakers: Paginated<PublicSpeakerSummary>;
    nav: ProgramNav;
    /** URL del aviso de privacidad publicado, o `null` si no hay. */
    privacyUrl: string | null;
}

/**
 * Lista pública de ponentes del evento: `/{organización}/events/{evento}/speakers`. Tarjetas en una
 * columna en móvil y en rejilla desde `lg`, paginadas en el servidor. Sin ponentes visibles: mensaje y `noindex`.
 * Metadatos mínimos aquí (título, descripción, `og:*`); canonical y `hreflang` llegan con F4.
 */
export default function Speakers({ event, organization, speakers, nav, privacyUrl }: SpeakersProps) {
    const { t } = useTranslation();

    const isEmpty = speakers.data.length === 0;
    const title = `${t('speakers.title')} · ${event.name} · ${organization.name}`;
    const description = t('speakers.description', { event: event.name });

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={title}>
                <meta name="description" content={description} />
                <meta property="og:title" content={title} />
                <meta property="og:description" content={description} />
                {isEmpty && <meta name="robots" content="noindex" />}
            </Head>
            <div className="flex flex-col gap-8">
                <PageBanner eyebrow={event.name} title={t('speakers.title')} titleId="speakers-title" logoUrl={event.logoUrl} logoName={event.name} />

                {isEmpty ? (
                    <section aria-labelledby="speakers-empty" className="flex flex-col gap-2">
                        <h2 id="speakers-empty" className={SECTION_HEADING}>
                            {t('speakers.empty_title')}
                        </h2>
                        <p className="pt-2 text-base text-text-muted">{t('speakers.empty_hint')}</p>
                    </section>
                ) : (
                    <section aria-labelledby="speakers-list" className="flex flex-col gap-6">
                        <h2 id="speakers-list" className={SECTION_HEADING}>
                            {t('speakers.heading')}
                        </h2>
                        <ul className="grid gap-4 lg:grid-cols-2">
                            {speakers.data.map((speaker) => (
                                <li key={speaker.id} className="flex">
                                    <SpeakerCard speaker={speaker} className="w-full" />
                                </li>
                            ))}
                        </ul>
                        <PageNav page={speakers} label={t('speakers.pagination_label')} />
                    </section>
                )}

                <div className={PAGE_FOOTER}>
                    <div className="flex flex-wrap items-center gap-x-4">
                        <TextLink href={nav.eventUrl}>{t('speakers.back_to_event')}</TextLink>
                        <TextLink href={nav.programUrl}>{t('speakers.program_link')}</TextLink>
                        {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                    </div>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </div>
        </PublicLayout>
    );
}
