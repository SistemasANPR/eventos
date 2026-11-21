import { useId } from 'react';

import { HexagonPattern } from '@/components/atoms/HexagonPattern';
import { BUTTON_PRIMARY, FOCUS_RING, HERO_PANEL, HERO_TITLE, PAGE_TITLE } from '@/lib/classes';
import { cn } from '@/lib/utils';
import type { LandingTone, PublicHeroBlock } from '@/types/landing';

interface LandingHeroBlockProps {
    block: PublicHeroBlock;
    /** `true` si su título es el encabezado principal (h1) de la página. */
    isMainHeading: boolean;
}

const LINK_REL = 'noopener noreferrer nofollow';

/** Anillo de foco sobre el bloque violeta: lima, como en la cabecera (design.md §3.1). */
const FOCUS_RING_ON_DARK = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cta';

const TONE_CLASSES: Record<LandingTone, { panel: string; title: string; subtitle: string; button: string; hexagons: string }> = {
    light: {
        panel: 'overflow-hidden rounded-3xl border border-border bg-surface text-text shadow-lg',
        title: PAGE_TITLE,
        subtitle: 'text-text-muted',
        button: cn(BUTTON_PRIMARY, FOCUS_RING),
        hexagons: 'text-brand/20',
    },
    accent: {
        panel: HERO_PANEL,
        title: HERO_TITLE,
        subtitle: 'text-primary-contrast',
        button: cn(BUTTON_PRIMARY, FOCUS_RING_ON_DARK),
        hexagons: 'text-brand-cyan/50',
    },
};

/**
 * Portada de la landing: título, subtítulo, imagen y un botón con enlace `https`. La imagen va
 * encima del texto (no detrás) para que el contraste del texto salga siempre de los tokens.
 * El tono `accent` es el bloque violeta de la marca; `light`, una tarjeta blanca.
 * Usar en: `LandingRenderer`.
 */
export function LandingHeroBlock({ block, isMainHeading }: LandingHeroBlockProps) {
    const headingId = useId();
    const tone = TONE_CLASSES[block.tone];
    const Heading = isMainHeading ? 'h1' : 'h2';
    const isCentered = block.align === 'center';

    return (
        <section aria-labelledby={headingId} className={tone.panel}>
            {block.imageUrl !== null && (
                <img src={block.imageUrl} alt={block.imageAlt ?? ''} className="h-48 w-full object-cover sm:h-80" />
            )}
            <div className={cn('relative flex flex-col gap-5 overflow-hidden p-6 sm:p-12', isCentered ? 'items-center text-center' : 'items-start')}>
                <HexagonPattern className={cn('absolute -right-10 -top-10 h-48 w-48 sm:h-72 sm:w-72', tone.hexagons)} />
                <Heading id={headingId} className={cn('relative', tone.title)}>
                    {block.title}
                </Heading>
                {block.subtitle !== null && <p className={cn('relative max-w-prose text-lg', tone.subtitle)}>{block.subtitle}</p>}
                {block.button !== null && (
                    <a href={block.button.url} rel={LINK_REL} className={cn('relative', tone.button)}>
                        {block.button.label}
                    </a>
                )}
            </div>
        </section>
    );
}
