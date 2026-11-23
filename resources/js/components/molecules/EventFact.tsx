import type { ReactNode } from 'react';

import { HexagonPattern } from '@/components/atoms/HexagonPattern';
import { cn } from '@/lib/utils';

interface EventFactProps {
    label: string;
    children: ReactNode;
    /**
     * `card` (por defecto): tarjeta con sombra y filete lima; `tile`: ficha índigo con hexágonos y
     * el dato grande; `plain`: sin caja, para usarlo dentro de otra tarjeta.
     */
    variant?: 'card' | 'tile' | 'plain';
    className?: string;
}

const BOX: Record<NonNullable<EventFactProps['variant']>, string> = {
    card: 'rounded-2xl border border-border border-t-4 border-t-cta bg-surface p-5 shadow-md',
    tile: 'relative overflow-hidden rounded-2xl border-t-4 border-t-cta bg-brand-deep p-5 text-primary-contrast shadow-md sm:p-6',
    plain: '',
};

const LABEL: Record<NonNullable<EventFactProps['variant']>, string> = {
    card: 'text-xs font-bold uppercase tracking-widest text-support',
    tile: 'text-xs font-bold uppercase tracking-widest text-primary-contrast',
    plain: 'text-sm font-medium text-text-muted',
};

const VALUE: Record<NonNullable<EventFactProps['variant']>, string> = {
    card: 'font-display text-lg font-bold leading-snug text-text',
    tile: 'font-display text-lg font-bold leading-snug text-primary-contrast sm:text-xl',
    plain: 'text-base text-text',
};

/**
 * Un dato del evento con su etiqueta (fechas, sede, zona horaria).
 * Usar dentro de un `<dl>`; no para texto corrido.
 */
export function EventFact({ label, children, variant = 'card', className }: EventFactProps) {
    return (
        <div className={cn('flex flex-col gap-1', BOX[variant], className)}>
            {variant === 'tile' && <HexagonPattern className="absolute -right-5 -top-5 h-24 w-24 text-brand-cyan/40" />}
            <dt className={cn('relative', LABEL[variant])}>{label}</dt>
            <dd className={cn('relative', VALUE[variant])}>{children}</dd>
        </div>
    );
}
