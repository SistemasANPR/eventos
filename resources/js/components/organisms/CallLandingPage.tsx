import { Head } from '@inertiajs/react';

import { buildLandingView } from '@/components/organisms/call-landing/landing-view';
import { Footer, StickyCta, TopBar } from '@/components/organisms/call-landing/LandingParts';
import { useIsPromoReached } from '@/components/organisms/CallLanding';
import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import { useTranslation } from '@/hooks/useTranslation';
import { zoneLabelOf } from '@/lib/call-landing-content';
import type { PublicCall } from '@/types/call';
import type { PublicOrganization } from '@/types/event';
import type { PublicLandingBlock } from '@/types/landing';

interface CallLandingPageProps {
    call: PublicCall;
    event: { name: string; logoUrl?: string | null };
    organization: PublicOrganization;
    /** Bloques de la landing propia de la convocatoria, ya resueltos por el servidor. */
    blocks: PublicLandingBlock[];
    eventUrl: string;
    privacyUrl: string | null;
    submitUrl: string | null;
}

/**
 * Página pública de una convocatoria con landing propia publicada (plan 033): la barra y el pie de la asociación, los
 * bloques que armó el organizador en el editor (el mismo `LandingRenderer` del lienzo) y la barra fija del teléfono.
 * Usar en: `Calls/Show`, cuando el servidor entrega `callLanding` y la convocatoria está en español.
 */
export function CallLandingPage({ call, event, organization, blocks, eventUrl, privacyUrl, submitUrl }: CallLandingPageProps) {
    const { t } = useTranslation();
    const { promoRef, isReached } = useIsPromoReached('.cl-band--promo');
    const view = buildLandingView(call, { timezoneLabel: zoneLabelOf(call.timezone) }, submitUrl);

    const links = [
        ...(call.showBackLink === false ? [] : [{ label: 'Volver al evento', href: eventUrl }]),
        ...(privacyUrl === null ? [] : [{ label: 'Aviso de privacidad', href: privacyUrl }]),
        ...(call.extraLinks ?? []).map((link) => ({ label: link.label, href: link.url, rel: 'noopener noreferrer nofollow' })),
        ...call.locales
            .filter((locale) => locale !== call.locale)
            .map((locale) => ({ label: t(`languages.${locale}`), href: `?lang=${locale}`, lang: locale })),
    ];

    return (
        <div className="cl">
            <Head title={`${call.title} · ${event.name} · ${organization.name}`}>
                <meta name="description" content={call.description ?? `${call.title} · ${event.name}`} />
                <meta property="og:title" content={`${call.title} · ${event.name}`} />
            </Head>
            <a className="cl-saltar" href="#contenido">
                Saltar al contenido
            </a>
            <TopBar view={view} />
            <main id="contenido" tabIndex={-1}>
                <div ref={promoRef}>
                    <LandingRenderer
                        blocks={blocks}
                        locale={call.locale}
                        t={t}
                        call={{ call, submitUrl, logoUrl: event.logoUrl ?? null }}
                    />
                </div>
            </main>
            <Footer links={links} />
            <StickyCta view={view} isHidden={isReached} />
        </div>
    );
}
