import { useId } from 'react';

import { SECTION_HEADING } from '@/lib/classes';
import type { PublicImageGalleryBlock } from '@/types/landing';

interface LandingGalleryBlockProps {
    block: PublicImageGalleryBlock;
}

/** Galería de imágenes (hasta 12), cada una con su texto alternativo. Usar en: `LandingRenderer`. */
export function LandingGalleryBlock({ block }: LandingGalleryBlockProps) {
    const headingId = useId();

    if (block.images.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby={block.title === null ? undefined : headingId} className="flex flex-col gap-4">
            {block.title !== null && (
                <h2 id={headingId} className={SECTION_HEADING}>
                    {block.title}
                </h2>
            )}
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {block.images.map((image, index) => (
                    <li key={`${index}-${image.url}`}>
                        <img
                            src={image.url}
                            alt={image.alt}
                            loading="lazy"
                            className="aspect-square w-full rounded-lg border border-border object-cover"
                        />
                    </li>
                ))}
            </ul>
        </section>
    );
}
