import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface EventFactProps {
    label: string;
    children: ReactNode;
    className?: string;
}

/**
 * Un dato del evento con su etiqueta (fechas, sede, zona horaria).
 * Usar dentro de un `<dl>`; no para texto corrido.
 */
export function EventFact({ label, children, className }: EventFactProps) {
    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <dt className="text-sm font-medium text-text-muted">{label}</dt>
            <dd className="text-base text-text">{children}</dd>
        </div>
    );
}
