import { CallRegisterButton } from '@/components/molecules/CallRegisterButton';
import { CallStateBadge } from '@/components/molecules/CallStateBadge';
import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import { useTranslation } from '@/hooks/useTranslation';
import type { Translate } from '@/lib/translate';
import type { CallState } from '@/types/call';
import type { PublicLandingBlock, PublicLandingCall } from '@/types/landing';

const CALL_STATES: CallState[] = ['open', 'scheduled', 'paused', 'closed'];

/** Dibuja un solo bloque con el renderizador de la landing. */
function BlockDemo({ build }: { build: (t: Translate) => PublicLandingBlock }) {
    const { t, locale } = useTranslation();

    return <LandingRenderer blocks={[build(t)]} locale={locale} t={t} />;
}

function demoCall(t: Translate, state: CallState): PublicLandingCall {
    return {
        title: t('catalog.demo_data.call_title'),
        submissionType: t('catalog.demo_data.fact_label'),
        state,
        opensAt: '2027-01-10T15:00:00Z',
        closesAt: '2027-02-15T23:00:00Z',
        timezone: 'America/Mexico_City',
        timeFormat: '12h',
        registerUrl: state === 'open' ? '#' : null,
    };
}

/** Demo de `LandingRenderer`: una landing breve con portada, datos, convocatoria abierta y cerrada, ejes y preguntas. */
export function LandingRendererDemo() {
    const { t, locale } = useTranslation();

    const blocks: PublicLandingBlock[] = [
        {
            id: 'd-hero',
            type: 'hero',
            title: t('catalog.demo_data.event_name'),
            subtitle: t('catalog.demo_data.event_description'),
            imageUrl: null,
            imageAlt: null,
            button: {
                label: t('catalog.demo_data.link'),
                url: 'https://example.test',
            },
            tone: 'accent',
            align: 'start',
        },
        {
            id: 'd-info',
            type: 'event_info',
            name: t('catalog.demo_data.event_name'),
            description: null,
            startsAt: '2027-03-10T15:00:00Z',
            endsAt: '2027-03-12T23:00:00Z',
            timezone: 'America/Mexico_City',
            timeFormat: '12h',
            venueName: t('catalog.demo_data.event_venue'),
            city: t('catalog.demo_data.event_city'),
        },
        {
            id: 'd-calls',
            type: 'calls',
            title: null,
            calls: [demoCall(t, 'open'), demoCall(t, 'closed')],
        },
        {
            id: 'd-tracks',
            type: 'tracks',
            title: null,
            tracks: [{ name: t('catalog.demo_data.track_name'), description: null }],
        },
        {
            id: 'd-faq',
            type: 'faq',
            title: null,
            items: [
                {
                    question: t('catalog.demo_data.faq_question'),
                    answerHtml: t('catalog.demo_data.rich_text'),
                },
            ],
        },
    ];

    return <LandingRenderer blocks={blocks} locale={locale} t={t} />;
}

/** Demo de `LandingHeroBlock`. */
export function LandingHeroBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd1',
                type: 'hero',
                title: t('catalog.demo_data.event_name'),
                subtitle: t('catalog.demo_data.event_description'),
                imageUrl: null,
                imageAlt: null,
                button: {
                    label: t('catalog.demo_data.link'),
                    url: 'https://example.test',
                },
                tone: 'light',
                align: 'center',
            })}
        />
    );
}

/** Demo de `LandingRichTextBlock`. */
export function LandingRichTextBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd2',
                type: 'rich_text',
                html: t('catalog.demo_data.rich_text'),
                width: 'narrow',
            })}
        />
    );
}

/** Demo de `LandingEventInfoBlock`. */
export function LandingEventInfoBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd3',
                type: 'event_info',
                name: t('catalog.demo_data.event_name'),
                description: t('catalog.demo_data.event_description'),
                startsAt: '2027-03-10T15:00:00Z',
                endsAt: '2027-03-12T23:00:00Z',
                timezone: 'America/Mexico_City',
                timeFormat: '24h',
                venueName: t('catalog.demo_data.event_venue'),
                city: t('catalog.demo_data.event_city'),
            })}
        />
    );
}

/** Demo de `LandingCallsBlock`: una convocatoria en cada estado. */
export function LandingCallsBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd4',
                type: 'calls',
                title: null,
                calls: CALL_STATES.map((state) => demoCall(t, state)),
            })}
        />
    );
}

/** Demo de `LandingTracksBlock`. */
export function LandingTracksBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd5',
                type: 'tracks',
                title: null,
                tracks: [
                    {
                        name: t('catalog.demo_data.track_name'),
                        description: t('catalog.demo_data.event_description'),
                    },
                    { name: t('catalog.demo_data.track_name'), description: null },
                ],
            })}
        />
    );
}

/** Demo de `LandingFaqBlock`. */
export function LandingFaqBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd6',
                type: 'faq',
                title: null,
                items: [
                    {
                        question: t('catalog.demo_data.faq_question'),
                        answerHtml: t('catalog.demo_data.rich_text'),
                    },
                ],
            })}
        />
    );
}

const PLACEHOLDER_IMAGE =
    'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect width="200" height="200" fill="%23cfdbf6"/></svg>';

/** Demo de `LandingGalleryBlock`. */
export function LandingGalleryBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd7',
                type: 'image_gallery',
                title: null,
                images: [1, 2, 3].map(() => ({
                    url: PLACEHOLDER_IMAGE,
                    alt: t('catalog.demo_data.image_alt'),
                })),
            })}
        />
    );
}

/** Demo de `LandingSpacerBlock`. */
export function LandingSpacerBlockDemo() {
    return <BlockDemo build={() => ({ id: 'd8', type: 'spacer', size: 'md' })} />;
}

/** Demo de `LandingColumnsBlock`. */
export function LandingColumnsBlockDemo() {
    return (
        <BlockDemo
            build={(t) => ({
                id: 'd9',
                type: 'columns',
                columns: [
                    { kind: 'text', html: t('catalog.demo_data.rich_text') },
                    {
                        kind: 'image',
                        url: PLACEHOLDER_IMAGE,
                        alt: t('catalog.demo_data.image_alt'),
                    },
                ],
            })}
        />
    );
}

/** Demo de `CallStateBadge`: los cuatro estados. */
export function CallStateBadgeDemo() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap gap-2">
            {CALL_STATES.map((state) => (
                <CallStateBadge key={state} state={state} t={t} />
            ))}
        </div>
    );
}

/** Demo de `CallRegisterButton`: abierta (enlace) y cerrada (bloqueado). */
export function CallRegisterButtonDemo() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap gap-3">
            <CallRegisterButton registerUrl="#" state="open" t={t} />
            <CallRegisterButton registerUrl={null} state="closed" t={t} />
        </div>
    );
}
