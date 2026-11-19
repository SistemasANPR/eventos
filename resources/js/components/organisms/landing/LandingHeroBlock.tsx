import { useId } from 'react';

import { BUTTON_PRIMARY, FOCUS_RING, PAGE_TITLE } from '@/lib/classes';
import { cn } from '@/lib/utils';
import type { LandingTone, PublicHeroBlock } from '@/types/landing';

interface LandingHeroBlockProps {
    block: PublicHeroBlock;
    /** `true` si su título es el encabezado principal (h1) de la página. */
    isMainHeading: boolean;
}

const LINK_REL = 'noopener noreferrer nofollow';

const TONE_CLASSES: Record<LandingTone, { panel: string; subtitle: string; button: string }> = {
    light: {
        panel: 'border border-border bg-surface text-text',
        subtitle: 'text-text-muted',
        button: cn(BUTTON_PRIMARY, FOCUS_RING),
    },
    accent: {
        panel: 'bg-primary text-primary-contrast',
        subtitle: 'text-primary-contrast',
        button: 'inline-flex min-h-11 items-center justify-center rounded-md bg-accent px-6 text-base font-semibold text-accent-contrast hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
    },
};

/**
 * Portada de la landing: título, subtítulo, imagen y un botón con enlace `https`. La imagen va
 * encima del texto (no detrás) para que el contraste del texto salga siempre de los tokens.
 * Usar en: `LandingRenderer`.
 */
export function LandingHeroBlock({ block, isMainHeading }: LandingHeroBlockProps) {
    const headingId = useId();
    const tone = TONE_CLASSES[block.tone];
    const Heading = isMainHeading ? 'h1' : 'h2';
    const isCentered = block.align === 'center';

    return (
        <section aria-labelledby={headingId} className={cn('overflow-hidden rounded-lg', tone.panel)}>
            {block.imageUrl !== null && (
                <img src={block.imageUrl} alt={block.imageAlt ?? ''} className="h-48 w-full object-cover sm:h-72" />
            )}
            <div className={cn('flex flex-col gap-4 p-6 sm:p-10', isCentered ? 'items-center text-center' : 'items-start')}>
                <Heading id={headingId} className={cn(PAGE_TITLE, 'text-inherit')}>
                    {block.title}
                </Heading>
                {block.subtitle !== null && <p className={cn('max-w-prose text-lg', tone.subtitle)}>{block.subtitle}</p>}
                {block.button !== null && (
                    <a href={block.button.url} rel={LINK_REL} className={tone.button}>
                        {block.button.label}
                    </a>
                )}
            </div>
        </section>
    );
}
