import { formatLandingDate, formatLandingTime } from '@/lib/call-landing-view';
import { findCallLandingOverride } from '@/lib/call-landings';
import type { PublicCall } from '@/types/call';
import type {
    CallLandingContent,
    CallLandingData,
    CallLandingOverride,
    CallRouteSlugs,
    LandingAxis,
    LandingFaq,
    LandingIconName,
    LandingPromo,
    LandingRequirementsAside,
    LandingStep,
    RichText,
} from '@/types/call-landing';

export interface LandingInput {
    call: PublicCall;
    event: { name: string; logoUrl?: string | null };
    slugs: CallRouteSlugs;
    data: CallLandingData;
}

/** Iconos que se reparten, en orden, entre los ejes del evento. */
export const AXIS_ICONS: LandingIconName[] = ['hoja', 'personas', 'plano', 'chip', 'ciudad', 'monedas'];

/** «GMT-6» y similares: la etiqueta de la zona horaria cuando la convocatoria no trae una propia (por ejemplo, «CDMX»). */
export function zoneLabelOf(timezone: string): string {
    const part = new Intl.DateTimeFormat('es-MX', { timeZone: timezone, timeZoneName: 'short' })
        .formatToParts(new Date())
        .find((p) => p.type === 'timeZoneName');

    return part?.value ?? timezone;
}

function axesOf(input: LandingInput): CallLandingContent['axes'] {
    const items: LandingAxis[] = input.call.tracks.map((track, index) => ({
        icon: AXIS_ICONS[index % AXIS_ICONS.length] as LandingIconName,
        name: track.name,
        description: track.description ?? '',
    }));

    return {
        heading: 'Ejes temáticos',
        intro: 'Tu propuesta debe enmarcarse en uno de los ejes de esta edición. En el formulario eliges el que mejor la describa.',
        items,
    };
}

function requirementsOf({ call, data }: LandingInput): CallLandingContent['requirements'] {
    const items: RichText[] = [
        data.maxParticipants > 1
            ? `Participa de forma **individual o en equipo**, con un máximo de **${data.maxParticipants} personas** en total.`
            : 'La propuesta es **individual**.',
    ];

    if (data.minParticipants > 1) {
        items.push(`Cada propuesta necesita al menos **${data.minParticipants} personas**.`);
    }

    if (call.tracks.length > 0) {
        items.push('Cada propuesta se registra en **un solo eje temático**: elige el que corresponda a su tema central.');
    }

    const groups = [
        { title: 'Información de la propuesta', fields: data.formProposal },
        { title: 'Información de cada participante', fields: data.formParticipant },
    ]
        .filter((group) => group.fields.length > 0)
        .map((group) => ({
            title: group.title,
            fields: group.fields.map((field) => ({ name: field.name, isRequired: field.isRequired })),
        }));

    const aside: LandingRequirementsAside | undefined =
        groups.length === 0 ? undefined : { kind: 'form', title: 'Lo que te pide el formulario', requiredNote: '* obligatorio', groups };

    return {
        heading: 'Prepara tu propuesta',
        intro: 'Ten listos estos datos antes de abrir el formulario; así lo completas en una sola sesión.',
        items,
        formButton: 'Abrir el formulario',
        aside,
    };
}

function stepsOf({ call }: LandingInput): LandingStep[] {
    const results =
        call.resultsAt === undefined
            ? 'Te avisaremos el resultado por correo.'
            : `Conoce los resultados el ${formatLandingDate(call.resultsAt, call.timezone)}.`;

    return [
        { icon: 'doc', title: 'Prepara', text: 'Reúne la información que pide el formulario.' },
        { icon: 'enviar', title: 'Envía', text: 'Completa el formulario en línea antes de la fecha de cierre.' },
        { icon: 'lupa', title: 'Evaluación', text: 'Las propuestas recibidas serán revisadas por el comité evaluador.' },
        { icon: 'premio', title: 'Resultados', text: results },
        { icon: 'micro', title: 'Presenta', text: 'Las propuestas seleccionadas forman parte del programa del evento.' },
    ];
}

function faqsOf({ call, data }: LandingInput, timezoneLabel: string): LandingFaq[] {
    const faqs: LandingFaq[] = [
        {
            question: '¿Cuándo cierra la convocatoria?',
            answer: [
                {
                    paragraph: `El ${formatLandingDate(call.closesAt, call.timezone)}, ${formatLandingTime(call.closesAt, call.timezone, timezoneLabel)}.`,
                },
            ],
        },
        {
            question: '¿Cuántas personas pueden participar en una propuesta?',
            answer: [
                {
                    paragraph:
                        data.maxParticipants > 1
                            ? `Hasta ${data.maxParticipants} personas por propuesta. La primera persona registrada es la principal y recibe la confirmación.`
                            : 'La propuesta es individual.',
                },
            ],
        },
    ];

    if (call.resultsAt !== undefined) {
        faqs.push({
            question: '¿Cuándo sabré el resultado?',
            answer: [{ paragraph: `El ${formatLandingDate(call.resultsAt, call.timezone)}.` }],
        });
    }

    return faqs;
}

function promosOf(input: LandingInput, override: CallLandingOverride | undefined): LandingPromo[] {
    return input.data.otherCalls.map((other) => {
        const own = override?.promos?.[other.slug];
        // Si la otra convocatoria tiene portada propia (en su archivo de textos), su anuncio la usa.
        const otherCover = findCallLandingOverride({ ...input.slugs, call: other.slug })?.cover?.url;

        return {
            url: other.url,
            title: own?.title ?? other.title,
            text: own?.text ?? other.description ?? 'También puedes participar en otra convocatoria de este evento.',
            button: own?.button ?? 'Ver convocatoria',
            coverUrl: own?.coverUrl ?? otherCover,
        };
    });
}

/**
 * Arma el contenido de la landing de una convocatoria: todo sale de sus datos reales, con textos de relleno
 * genéricos donde falta información, y el retoque propio de la convocatoria (`call-landings.ts`), si lo hay,
 * reemplaza lo automático. No inventa datos que no existan (fechas del congreso, sede, especificaciones...).
 */
export function buildLandingContent(
    input: LandingInput,
    override: CallLandingOverride | undefined = findCallLandingOverride(input.slugs),
): CallLandingContent {
    const { call, event } = input;
    const timezoneLabel = override?.timezoneLabel ?? zoneLabelOf(call.timezone);
    const lede = call.description ?? `Envía tu propuesta a ${event.name}.`;
    // Los anuncios se arman aparte (`promosOf`): del retoque solo se toman los demás textos.
    const texts: CallLandingOverride = { ...override };
    delete texts.promos;

    return {
        title: call.title,
        eventLine: event.name,
        lede,
        cover: event.logoUrl ? { url: event.logoUrl, alt: event.name, isLogo: true } : undefined,
        seo: { description: lede, ogTitle: `${call.title} · ${event.name}`, ogDescription: lede },
        timezoneLabel,
        axes: axesOf(input),
        requirements: requirementsOf(input),
        steps: stepsOf(input),
        faqs: faqsOf(input, timezoneLabel),
        ...texts,
        promos: promosOf(input, override),
    };
}
