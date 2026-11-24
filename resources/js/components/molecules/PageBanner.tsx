import type { ReactNode } from 'react';

import { EventLogo } from '@/components/atoms/EventLogo';
import { HexagonPattern } from '@/components/atoms/HexagonPattern';
import { HERO_PANEL, HERO_TITLE, LOGO_PLATE } from '@/lib/classes';
import { cn } from '@/lib/utils';

interface PageBannerProps {
    /** Texto pequeño sobre el título (nombre del evento y de la convocatoria). */
    eyebrow?: string;
    /** Título de la página: es el `h1`. */
    title: string;
    /** `id` del `h1`, para que la página lo use como `aria-labelledby`. */
    titleId: string;
    /** Logotipo del evento y su texto alternativo; sin logotipo no se dibuja nada. */
    logoUrl?: string | null;
    logoName?: string;
    /** Adorno sobre el título (p. ej. `HexagonBadge`). */
    badge?: ReactNode;
    /** `center` centra todo el contenido. */
    align?: 'start' | 'center';
    /** Contenido bajo el título: estado, descripción, botón de acción. */
    children?: ReactNode;
    /** Espacio extra abajo para que las tarjetas siguientes se monten sobre el borde. */
    hasOverlap?: boolean;
    /** Más aire arriba y abajo: portada de una página sin tarjetas debajo. */
    isRoomy?: boolean;
}

/**
 * Encabezado de color de las páginas de la convocatoria: bloque violeta con degradado, hexágonos,
 * logotipo, título grande y un hueco para el estado y la acción principal.
 * Usar en: convocatoria, formulario de envío y confirmación; no en páginas de lectura o de autor.
 */
export function PageBanner({
    eyebrow,
    title,
    titleId,
    logoUrl,
    logoName = '',
    badge,
    align = 'start',
    children,
    hasOverlap = false,
    isRoomy = false,
}: PageBannerProps) {
    const isCentered = align === 'center';

    return (
        <header
            className={cn(
                HERO_PANEL,
                isRoomy ? 'px-6 pt-10 sm:px-14 sm:pt-16' : 'px-6 pt-8 sm:px-12 sm:pt-12',
                hasOverlap ? 'pb-20 sm:pb-24' : isRoomy ? 'pb-10 sm:pb-16' : 'pb-8 sm:pb-12',
            )}
        >
            <HexagonPattern className="absolute -right-12 -top-12 h-56 w-56 text-brand-cyan/50 sm:-right-6 sm:h-80 sm:w-80" />
            <HexagonPattern className="absolute -bottom-16 left-1/4 h-40 w-40 text-cta/40" />
            <div className={cn('relative flex flex-col gap-4', isCentered ? 'items-center text-center' : 'items-start')}>
                {badge}
                <EventLogo src={logoUrl} name={logoName} className={LOGO_PLATE} />
                {eyebrow !== undefined && (
                    <p className="text-sm font-semibold uppercase tracking-widest text-primary-contrast">{eyebrow}</p>
                )}
                <h1 id={titleId} className={HERO_TITLE}>
                    {title}
                </h1>
                {children}
            </div>
        </header>
    );
}
