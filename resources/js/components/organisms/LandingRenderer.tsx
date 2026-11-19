import type { ReactNode } from 'react';

import { LandingCallsBlock } from '@/components/organisms/landing/LandingCallsBlock';
import { LandingColumnsBlock } from '@/components/organisms/landing/LandingColumnsBlock';
import { LandingEventInfoBlock } from '@/components/organisms/landing/LandingEventInfoBlock';
import { LandingFaqBlock } from '@/components/organisms/landing/LandingFaqBlock';
import { LandingGalleryBlock } from '@/components/organisms/landing/LandingGalleryBlock';
import { LandingHeroBlock } from '@/components/organisms/landing/LandingHeroBlock';
import { LandingRichTextBlock } from '@/components/organisms/landing/LandingRichTextBlock';
import { LandingSpacerBlock } from '@/components/organisms/landing/LandingSpacerBlock';
import { LandingTracksBlock } from '@/components/organisms/landing/LandingTracksBlock';
import type { Translate } from '@/lib/translate';
import type { PublicLandingBlock } from '@/types/landing';

interface LandingRendererProps {
    blocks: PublicLandingBlock[];
    /** Idioma de los textos y del formato de fechas. */
    locale: string;
    t: Translate;
    /**
     * Envuelve cada bloque ya dibujado. El editor lo usa para hacerlos seleccionables; el sitio
     * público no lo pasa.
     */
    wrapBlock?: (block: PublicLandingBlock, index: number, content: ReactNode) => ReactNode;
}

/**
 * Dibuja los bloques de la landing de un evento, en orden. Es el MISMO renderizador del sitio
 * público y del lienzo del editor (plan 022, ADR-0010): no lee Inertia, recibe `t` y `locale`.
 * El primer `hero` (o, sin portada, el primer `event_info`) lleva el h1 de la página.
 * Usar en: página pública del evento y lienzo del editor.
 */
export function LandingRenderer({ blocks, locale, t, wrapBlock }: LandingRendererProps) {
    const mainIndex = mainHeadingIndex(blocks);

    return (
        <div className="flex flex-col gap-8">
            {blocks.map((block, index) => {
                const content = renderBlock(block, index === mainIndex, locale, t);

                return <div key={block.id}>{wrapBlock === undefined ? content : wrapBlock(block, index, content)}</div>;
            })}
        </div>
    );
}

/** Posición del bloque que lleva el h1: el primer `hero`, si no el primer `event_info`, si no ninguno (-1). */
function mainHeadingIndex(blocks: PublicLandingBlock[]): number {
    const hero = blocks.findIndex((block) => block.type === 'hero');

    return hero !== -1 ? hero : blocks.findIndex((block) => block.type === 'event_info');
}

function renderBlock(block: PublicLandingBlock, isMainHeading: boolean, locale: string, t: Translate): ReactNode {
    switch (block.type) {
        case 'hero':
            return <LandingHeroBlock block={block} isMainHeading={isMainHeading} />;
        case 'rich_text':
            return <LandingRichTextBlock block={block} />;
        case 'event_info':
            return <LandingEventInfoBlock block={block} locale={locale} t={t} isMainHeading={isMainHeading} />;
        case 'calls':
            return <LandingCallsBlock block={block} locale={locale} t={t} />;
        case 'tracks':
            return <LandingTracksBlock block={block} t={t} />;
        case 'faq':
            return <LandingFaqBlock block={block} t={t} />;
        case 'image_gallery':
            return <LandingGalleryBlock block={block} />;
        case 'spacer':
            return <LandingSpacerBlock block={block} />;
        case 'columns':
            return <LandingColumnsBlock block={block} />;
    }
}
