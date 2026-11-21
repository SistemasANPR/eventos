import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface EventFactProps {
    label: string;
    children: ReactNode;
    /** `card` (por defecto): tarjeta con sombra y filete lima; `plain`: sin caja, para usarlo dentro de otra tarjeta. */
    variant?: 'card' | 'plain';
    className?: string;
}

/**
 * Un dato del evento con su etiqueta (fechas, sede, zona horaria).
 * Usar dentro de un `<dl>`; no para texto corrido.
 */
export function EventFact({ label, children, variant = 'card', className }: EventFactProps) {
    const isCard = variant === 'card';

    return (
        <div
            className={cn(
                'flex flex-col gap-1',
                isCard ? 'rounded-2xl border border-border border-t-4 border-t-cta bg-surface p-5 shadow-md' : null,
                className,
            )}
        >
            <dt className={isCard ? 'text-xs font-bold uppercase tracking-widest text-support' : 'text-sm font-medium text-text-muted'}>
                {label}
            </dt>
            <dd className={isCard ? 'font-display text-lg font-bold leading-snug text-text' : 'text-base text-text'}>{children}</dd>
        </div>
    );
}
