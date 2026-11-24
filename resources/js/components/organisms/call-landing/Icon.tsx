import type { ReactNode } from 'react';

import type { LandingIconName } from '@/types/call-landing';

/** Trazos de 24 × 24 de cada icono del diseño; toman el color del texto que los rodea. */
const PATHS: Record<LandingIconName, ReactNode> = {
    hoja: (
        <>
            <path d="M5 19c0-8.5 5-14 15-14 0 10-5.5 15-14 15" />
            <path d="M5 19l8.5-8.5" />
        </>
    ),
    personas: (
        <>
            <circle cx="9" cy="8" r="3.2" />
            <path d="M3 19.5c0-3.4 2.7-5.7 6-5.7s6 2.3 6 5.7" />
            <circle cx="17" cy="9" r="2.5" />
            <path d="M15.6 13.8c.5-.1.9-.2 1.4-.2 2.5 0 4 1.8 4 4.6" />
        </>
    ),
    plano: (
        <>
            <rect x="3.5" y="3.5" width="17" height="17" rx="2" />
            <path d="M3.5 9.5h17M9.5 9.5v11M14 14h3.5M14 17h2" />
        </>
    ),
    chip: (
        <>
            <rect x="7" y="7" width="10" height="10" rx="1.6" />
            <path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4" />
        </>
    ),
    ciudad: (
        <>
            <path d="M3 20.5h18M5 20.5V9l5-3.5v15M10 20.5V11h9v9.5" />
            <path d="M13 14h1M16 14h0M13 17h1M16 17h0M7.5 11v0M7.5 14.5v0" />
        </>
    ),
    monedas: (
        <>
            <ellipse cx="9" cy="6.5" rx="5.5" ry="2.5" />
            <path d="M3.5 6.5v4.5c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5V6.5" />
            <path d="M9.5 15.3c.6 1.2 2.8 2.2 5.5 2.2 3 0 5.5-1.1 5.5-2.5v-4.5c0-1.2-1.9-2.2-4.5-2.4" />
            <path d="M9.5 15.3V19c0 1.4 2.5 2.5 5.5 2.5s5.5-1.1 5.5-2.5v-4" />
        </>
    ),
    calendario: (
        <>
            <rect x="3.5" y="5" width="17" height="15.5" rx="2" />
            <path d="M3.5 10h17M8 3v4M16 3v4" />
        </>
    ),
    reloj: (
        <>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M12 7.5V12l3 2" />
        </>
    ),
    premio: (
        <>
            <circle cx="12" cy="9" r="5.5" />
            <path d="M8.6 13.4 7 21l5-2.6 5 2.6-1.6-7.6" />
        </>
    ),
    pin: (
        <>
            <path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z" />
            <circle cx="12" cy="10" r="2.3" />
        </>
    ),
    check: <path d="m5 12.5 4.5 4.5L19 7.5" />,
    doc: (
        <>
            <path d="M6.5 3h7l4.5 4.5V21h-11.5z" />
            <path d="M13.5 3v4.5H18M9 13h6M9 16.5h6" />
        </>
    ),
    enviar: <path d="M21 3 10.5 13.5M21 3l-6.5 18-4-7.5L3 9.5z" />,
    lupa: (
        <>
            <circle cx="10.5" cy="10.5" r="6.5" />
            <path d="m15.5 15.5 5 5" />
        </>
    ),
    micro: (
        <>
            <rect x="9" y="3" width="6" height="11" rx="3" />
            <path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21" />
        </>
    ),
    impresora: (
        <>
            <path d="M7 9V3.5h10V9M7 17.5H4.5v-7a1.5 1.5 0 0 1 1.5-1.5h12a1.5 1.5 0 0 1 1.5 1.5v7H17" />
            <rect x="7" y="14" width="10" height="6.5" rx="1" />
        </>
    ),
    flecha: <path d="M5 12h14M13 6l6 6-6 6" />,
};

/** Icono decorativo de la landing (oculto para lectores de pantalla). */
export function Icon({ name }: { name: LandingIconName }) {
    return (
        <svg className="cl-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            {PATHS[name]}
        </svg>
    );
}
