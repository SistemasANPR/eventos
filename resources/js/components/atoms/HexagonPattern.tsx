import { cn } from '@/lib/utils';

interface HexagonPatternProps {
    /** Tamaño, posición y color (`text-*`): el trazo usa `currentColor`. */
    className?: string;
}

/**
 * Racimo decorativo de hexágonos (motivo de la marca) para cabeceras y portadas. Es puro adorno:
 * va oculto a los lectores de pantalla y no recibe eventos.
 * Usar en: cabecera y pie de página, portadas y encabezados de color; no para transmitir información.
 */
export function HexagonPattern({ className }: HexagonPatternProps) {
    return (
        <svg
            aria-hidden="true"
            focusable="false"
            viewBox="0 0 200 200"
            fill="none"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinejoin="round"
            className={cn('pointer-events-none select-none', className)}
        >
            <polygon points="100,10 140,33 140,79 100,102 60,79 60,33" />
            <polygon points="170,50 210,73 210,119 170,142 130,119 130,73" fill="currentColor" fillOpacity="0.18" />
            <polygon points="100,90 140,113 140,159 100,182 60,159 60,113" />
        </svg>
    );
}
