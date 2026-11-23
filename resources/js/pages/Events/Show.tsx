import { Head } from '@inertiajs/react';

import { EventLogo } from '@/components/atoms/EventLogo';
import { TextLink } from '@/components/atoms/TextLink';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { EventHero } from '@/components/organisms/EventHero';
import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { LOGO_PLATE, PAGE_FOOTER } from '@/lib/classes';
import type { PublicEvent, PublicOrganization } from '@/types/event';
import type { PublicLanding, PublicLandingBlock, PublicHeroBlock } from '@/types/landing';

interface ShowProps {
    event: PublicEvent;
    organization: PublicOrganization;
    /** URL del aviso de privacidad publicado, o `null` si no hay. */
    privacyUrl: string | null;
    /** Landing publicada del evento; `null` o ausente conserva la página actual (plan 022, CA-022-22). */
    landing?: PublicLanding | null;
}

/**
 * Página pública de un evento publicado: `/{organización}/events/{evento}`. Con landing publicada
 * dibuja sus bloques; sin ella, la presentación fija de siempre.
 */
export default function Show({ event, organization, privacyUrl, landing = null }: ShowProps) {
    const { t } = useTranslation();

    const hero = landing === null ? null : firstHero(landing.blocks);
    const description = event.description ?? hero?.subtitle ?? null;
    const title = `${event.name} · ${organization.name}`;

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={title}>
                {description !== null && <meta name="description" content={description} />}
                {landing !== null && <meta property="og:title" content={title} />}
                {landing !== null && description !== null && <meta property="og:description" content={description} />}
                {hero !== null && hero.imageUrl !== null && <meta property="og:image" content={hero.imageUrl} />}
            </Head>
            {landing === null ? (
                <EventHero event={event} privacyUrl={privacyUrl} />
            ) : (
                <div className="flex flex-col gap-8">
                    <EventLogo src={event.logoUrl} name={event.name} className={LOGO_PLATE} />
                    <LandingRenderer blocks={landing.blocks} locale={event.locale} t={t} />
                    <div className={PAGE_FOOTER}>
                        {privacyUrl !== null && <TextLink href={privacyUrl}>{t('event.privacy_link')}</TextLink>}
                        <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                    </div>
                </div>
            )}
        </PublicLayout>
    );
}

function firstHero(blocks: PublicLandingBlock[]): PublicHeroBlock | null {
    return blocks.find((block): block is PublicHeroBlock => block.type === 'hero') ?? null;
}
