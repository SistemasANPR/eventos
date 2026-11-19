import { RichText } from '@/components/atoms/RichText';
import { cn } from '@/lib/utils';
import type { PublicRichTextBlock } from '@/types/landing';

interface LandingRichTextBlockProps {
    block: PublicRichTextBlock;
}

/** Texto con formato (HTML ya saneado en el servidor) en columna estrecha o ancha. Usar en: `LandingRenderer`. */
export function LandingRichTextBlock({ block }: LandingRichTextBlockProps) {
    return <RichText html={block.html} className={cn(block.width === 'narrow' && 'max-w-prose')} />;
}
