import type { AnchorHTMLAttributes } from 'react';

import { FOCUS_RING } from '@/lib/classes';
import { cn } from '@/lib/utils';

interface TextLinkProps extends AnchorHTMLAttributes<HTMLAnchorElement> {
    /** Marca el enlace de la opción activa de un grupo (p. ej. el idioma actual). */
    isActive?: boolean;
}

/**
 * Enlace de texto con foco visible y objetivo táctil de al menos 40 px.
 * Usar para navegar entre páginas o variantes; no para acciones que cambian datos.
 */
export function TextLink({ isActive = false, className, children, ...rest }: TextLinkProps) {
    return (
        <a
            aria-current={isActive ? 'true' : undefined}
            className={cn(
                'inline-flex min-h-11 items-center rounded-md px-3 text-base underline-offset-4 hover:underline sm:min-h-8',
                FOCUS_RING,
                isActive ? 'font-semibold text-text no-underline' : 'text-primary',
                className,
            )}
            {...rest}
        >
            {children}
        </a>
    );
}
