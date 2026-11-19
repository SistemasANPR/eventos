import { cn } from '@/lib/utils';
import type { LandingSpacerSize, PublicSpacerBlock } from '@/types/landing';

interface LandingSpacerBlockProps {
    block: PublicSpacerBlock;
}

const SIZE_CLASSES: Record<LandingSpacerSize, string> = {
    sm: 'h-4',
    md: 'h-10',
    lg: 'h-20',
};

/** Espacio vertical decorativo, invisible para los lectores de pantalla. Usar en: `LandingRenderer`. */
export function LandingSpacerBlock({ block }: LandingSpacerBlockProps) {
    return <div aria-hidden="true" className={cn(SIZE_CLASSES[block.size])} />;
}
