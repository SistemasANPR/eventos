import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import {
    Axes,
    Dates,
    Faqs,
    Footer,
    Hero,
    type LandingView,
    Promo,
    Requirements,
    Steps,
    StickyCta,
    TopBar,
} from '@/components/organisms/call-landing/LandingParts';
import { useTranslation } from '@/hooks/useTranslation';
import { buildLandingContent } from '@/lib/call-landing-content';
import { countdownText, ctaLabel, formatLandingDate, formatLandingTime, landingStatus, statusLabel } from '@/lib/call-landing-view';
import type { PublicCall } from '@/types/call';
import type { CallLandingContent, CallLandingData, CallRouteSlugs } from '@/types/call-landing';
import type { PublicOrganization } from '@/types/event';

import '../../../css/call-landing.css';

interface CallLandingProps {
    call: PublicCall;
    event: { name: string; logoUrl?: string | null };
    /** Datos propios de la convocatoria que arma el servidor: formulario, límite de personas y otras convocatorias. */
    data: CallLandingData;
    organization: PublicOrganization;
    slugs: CallRouteSlugs;
    eventUrl: string;
    privacyUrl: string | null;
    submitUrl: string | null;
    /** Página para pedir el enlace de acceso a una propuesta ya enviada (plan 034); `null` si no se ofrece. */
    authorRequestUrl?: string | null;
}

function buildView(call: PublicCall, content: CallLandingContent, submitUrl: string | null): LandingView {
    return {
        status: landingStatus(call.state),
        statusText: statusLabel(call.state),
        countdown: countdownText(call.state, call.opensAt, call.closesAt, call.timezone),
        opensOn: formatLandingDate(call.opensAt, call.timezone),
        closesOn: formatLandingDate(call.closesAt, call.timezone),
        closesAt: formatLandingTime(call.closesAt, call.timezone, content.timezoneLabel),
        resultsOn: content.resultsLabel ?? (call.resultsAt === undefined ? undefined : formatLandingDate(call.resultsAt, call.timezone)),
        submitUrl,
        ctaLabel: ctaLabel(call.state),
    };
}

/** Oculta la barra fija del teléfono cuando el anuncio de otra convocatoria entra en pantalla (o ya quedó atrás). */
function useIsPromoReached() {
    const promoRef = useRef<HTMLDivElement>(null);
    const [isReached, setIsReached] = useState(false);

    useEffect(() => {
        const target = promoRef.current;

        if (target === null || typeof IntersectionObserver === 'undefined') {
            return undefined;
        }

        const observer = new IntersectionObserver(([entry]) => {
            setIsReached(entry !== undefined && (entry.isIntersecting || entry.boundingClientRect.top < 0));
        });
        observer.observe(target);

        return () => observer.disconnect();
    }, []);

    return { promoRef, isReached };
}

/**
 * Landing de toda convocatoria, con el diseño de la ANPR (aprobado el 24 nov 2026): barra de la asociación, portada con
 * estado y cuenta regresiva, fechas, ejes, requisitos, pasos, preguntas, anuncios de otras convocatorias y pie. Se arma con
 * los datos reales de la convocatoria (`buildLandingContent`); el texto propio de una convocatoria, si lo tiene, está en
 * `lib/call-landings.ts`.
 */
export function CallLanding({ call, event, data, organization, slugs, eventUrl, privacyUrl, submitUrl, authorRequestUrl = null }: CallLandingProps) {
    const { t } = useTranslation();
    const { promoRef, isReached } = useIsPromoReached();
    const content = useMemo(() => buildLandingContent({ call, event, slugs, data }), [call, event, slugs, data]);
    const view = buildView(call, content, submitUrl);

    const links = [
        ...(call.showBackLink === false ? [] : [{ label: 'Volver al evento', href: eventUrl }]),
        ...(authorRequestUrl === null ? [] : [{ label: t('call.already_submitted'), href: authorRequestUrl }]),
        ...(privacyUrl === null ? [] : [{ label: 'Aviso de privacidad', href: privacyUrl }]),
        ...(call.extraLinks ?? []).map((link) => ({ label: link.label, href: link.url, rel: 'noopener noreferrer nofollow' })),
        ...call.locales
            .filter((locale) => locale !== call.locale)
            .map((locale) => ({ label: t(`languages.${locale}`), href: `?lang=${locale}`, lang: locale })),
    ];

    return (
        <div className="cl">
            <Head title={`${call.title} · ${event.name} · ${organization.name}`}>
                <meta name="description" content={content.seo.description} />
                <meta property="og:title" content={content.seo.ogTitle} />
                <meta property="og:description" content={content.seo.ogDescription} />
            </Head>
            <a className="cl-saltar" href="#contenido">
                Saltar al contenido
            </a>
            <TopBar view={view} />
            <main id="contenido" tabIndex={-1}>
                <Hero content={content} view={view} />
                <Dates content={content} view={view} />
                <Axes content={content} />
                <Requirements content={content} view={view} />
                <Steps steps={content.steps} />
                <Faqs faqs={content.faqs} />
                <div ref={promoRef}>
                    {content.promos.map((promo, index) => (
                        <Promo key={promo.url} promo={promo} headingId={`t-otra-${index}`} />
                    ))}
                </div>
            </main>
            <Footer links={links} />
            <StickyCta view={view} isHidden={isReached} />
        </div>
    );
}
