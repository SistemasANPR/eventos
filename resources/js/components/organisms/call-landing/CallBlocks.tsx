import type { ReactNode } from 'react';

import { Axes, Dates, Faqs, Hero, Promo, Requirements, Steps } from '@/components/organisms/call-landing/LandingParts';
import { buildLandingView } from '@/components/organisms/call-landing/landing-view';
import { AXIS_ICONS, zoneLabelOf } from '@/lib/call-landing-content';
import type { PublicCall } from '@/types/call';
import type { CallLandingContent } from '@/types/call-landing';
import type {
    PublicCallAxesBlock,
    PublicCallDatesBlock,
    PublicCallHeroBlock,
    PublicCallPromosBlock,
    PublicCallRequirementsBlock,
    PublicCallStepsBlock,
    PublicFaqBlock,
} from '@/types/landing';

import '../../../../css/call-landing.css';

/** La convocatoria a la que pertenece la landing: las secciones leen de ella sus fechas, su estado y el botón al formulario. */
export interface CallRenderContext {
    call: PublicCall;
    /** Dirección del formulario; `null` si la convocatoria no recibe propuestas (el botón queda bloqueado). */
    submitUrl: string | null;
    /** Logotipo del evento: portada de reserva si la sección no trae imagen. */
    logoUrl: string | null;
}

function cover(block: PublicCallHeroBlock, context: CallRenderContext): CallLandingContent['cover'] {
    if (block.imageUrl !== null) {
        return { url: block.imageUrl, alt: block.imageAlt ?? '' };
    }

    return context.logoUrl === null ? undefined : { url: context.logoUrl, alt: block.eventLine, isLogo: true };
}

/** Portada de la convocatoria: título, estado con cuenta regresiva, texto y botón al formulario. */
export function CallHeroSection({ block, context }: { block: PublicCallHeroBlock; context: CallRenderContext }) {
    const view = buildLandingView(context.call, { timezoneLabel: zoneLabelOf(context.call.timezone) }, context.submitUrl);

    return (
        <Hero
            view={view}
            content={{
                title: block.title,
                eventLine: block.eventLine,
                lede: block.lede,
                notice: block.notice ?? undefined,
                cover: cover(block, context),
            }}
        />
    );
}

/** Fechas clave: apertura, cierre, resultados y, si se indica, la sede. */
export function CallDatesSection({ block, context }: { block: PublicCallDatesBlock; context: CallRenderContext }) {
    const view = buildLandingView(
        context.call,
        { timezoneLabel: block.timezoneLabel ?? zoneLabelOf(context.call.timezone), resultsLabel: block.resultsLabel ?? undefined },
        context.submitUrl,
    );

    return <Dates venue={block.venue ?? undefined} view={view} />;
}

/** Ejes temáticos del evento, con un icono por eje. */
export function CallAxesSection({ block }: { block: PublicCallAxesBlock }) {
    return (
        <Axes
            axes={{
                heading: block.title,
                intro: block.intro,
                items: block.axes.map((axis, index) => ({
                    icon: AXIS_ICONS[index % AXIS_ICONS.length] ?? 'hoja',
                    name: axis.name,
                    description: axis.description ?? '',
                })),
            }}
        />
    );
}

/** Requisitos y vista previa de lo que pide el formulario publicado. */
export function CallRequirementsSection({ block, context }: { block: PublicCallRequirementsBlock; context: CallRenderContext }) {
    const view = buildLandingView(context.call, { timezoneLabel: zoneLabelOf(context.call.timezone) }, context.submitUrl);

    return (
        <Requirements
            view={view}
            requirements={{
                heading: block.title,
                intro: block.intro ?? undefined,
                items: block.items,
                formButton: block.buttonLabel,
                aside:
                    block.form === null
                        ? undefined
                        : { kind: 'form', title: block.form.title, requiredNote: block.form.requiredNote, groups: block.form.groups },
            }}
        />
    );
}

export function CallStepsSection({ block }: { block: PublicCallStepsBlock }) {
    return <Steps heading={block.title} steps={block.steps} />;
}

/** Preguntas frecuentes con el diseño de la landing de convocatoria (la respuesta es texto con formato ya saneado). */
export function CallFaqSection({ block }: { block: PublicFaqBlock }) {
    return (
        <Faqs
            heading={block.title ?? 'Preguntas frecuentes'}
            intro={null}
            faqs={block.items.map((item) => ({ question: item.question, answer: [{ html: item.answerHtml }] }))}
        />
    );
}

/** Anuncios de las demás convocatorias del evento. */
export function CallPromosSection({ block }: { block: PublicCallPromosBlock }): ReactNode {
    return block.promos.map((promo, index) => (
        <Promo
            key={promo.url}
            headingId={`t-otra-${block.id}-${index}`}
            promo={{ url: promo.url, title: promo.title, text: promo.text, button: promo.button, coverUrl: promo.coverUrl ?? undefined }}
        />
    ));
}
