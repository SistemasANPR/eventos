import { cn } from '@/lib/utils';

interface HexagonBadgeProps {
    /** Tamaño y posición (`h-* w-*`); el hexágono se escala con el elemento. */
    className?: string;
}

/**
 * Hexágono lima con una palomita índigo: el sello de «listo» de las confirmaciones. Es adorno
 * puro (oculto a los lectores de pantalla): el estado siempre lo dice también un texto.
 * Usar en: la confirmación del envío y otras pantallas de éxito; no como botón ni como único aviso.
 */
export function HexagonBadge({ className }: HexagonBadgeProps) {
    return (
        <svg aria-hidden="true" focusable="false" viewBox="0 0 100 100" className={cn('drop-shadow-lg', className)}>
            <polygon
                points="50,3 95,26 95,74 50,97 5,74 5,26"
                className="fill-cta stroke-brand-cyan"
                strokeWidth="4"
                strokeLinejoin="round"
            />
            <path
                d="M31 51 L45 65 L70 36"
                fill="none"
                className="stroke-cta-contrast"
                strokeWidth="9"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
