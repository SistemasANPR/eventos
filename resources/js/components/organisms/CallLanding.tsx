import { Head } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { buildLandingView } from '@/components/organisms/call-landing/landing-view';
import {
    Axes,
    Dates,
    Faqs,
    Footer,
    Hero,
    Promo,
    Requirements,
    Steps,
    StickyCta,
    TopBar,
} from '@/components/organisms/call-landing/LandingParts';
import { useTranslation } from '@/hooks/useTranslation';
import { buildLandingContent } from '@/lib/call-landing-content';
import type { PublicCall } from '@/types/call';
import type { CallLandingData, CallRouteSlugs } from '@/types/call-landing';
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
}

/** Oculta la barra fija del teléfono cuando el anuncio de otra convocatoria entra en pantalla (o ya quedó atrás). */
export function useIsPromoReached(selector?: string) {
    const promoRef = useRef<HTMLDivElement>(null);
    const [isReached, setIsReached] = useState(false);

    useEffect(() => {
        // Con `selector`, el anuncio es el primer elemento que lo cumple dentro del contenedor (la landing del editor).
        const target = selector === undefined ? promoRef.current : (promoRef.current?.querySelector(selector) ?? null);

        if (target === null || typeof IntersectionObserver === 'undefined') {
            return undefined;
        }

        const observer = new IntersectionObserver(([entry]) => {
            setIsReached(entry !== undefined && (entry.isIntersecting || entry.boundingClientRect.top < 0));
        });
        observer.observe(target);

        return () => observer.disconnect();
    }, [selector]);

    return { promoRef, isReached };
}

/**
 * Landing de toda convocatoria, con el diseño de la ANPR (aprobado el 24 nov 2026): barra de la asociación, portada con
 * estado y cuenta regresiva, fechas, ejes, requisitos, pasos, preguntas, anuncios de otras convocatorias y pie. Se arma con
 * los datos reales de la convocatoria (`buildLandingContent`); el texto propio de una convocatoria, si lo tiene, está en
 * `lib/call-landings.ts`. Es la landing AUTOMÁTICA: una convocatoria con landing propia publicada (editor, plan 033) usa
 * `CallLandingPage`.
 */
export function CallLanding({ call, event, data, organization, slugs, eventUrl, privacyUrl, submitUrl }: CallLandingProps) {
    const { t } = useTranslation();
    const { promoRef, isReached } = useIsPromoReached();
    const content = useMemo(() => buildLandingContent({ call, event, slugs, data }), [call, event, slugs, data]);
    const view = buildLandingView(call, content, submitUrl);

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
                <Dates venue={content.venue} view={view} />
                <Axes axes={content.axes} />
                <Requirements requirements={content.requirements} view={view} />
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
