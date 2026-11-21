import type { ReactNode } from 'react';

import { HexagonPattern } from '@/components/atoms/HexagonPattern';
import { SkipToContent } from '@/components/infrastructure/SkipToContent';
import { useTranslation } from '@/hooks/useTranslation';
import { cn } from '@/lib/utils';

interface PublicLayoutProps {
    organizationName: string;
    /** Contenido ancho (cuadrículas de varias columnas) en lugar de la columna de lectura. */
    isWide?: boolean;
    children: ReactNode;
}

/** Estructura de las páginas públicas: salto al contenido, cabecera de color, contenido principal y pie. */
export function PublicLayout({ organizationName, isWide = false, children }: PublicLayoutProps) {
    const { t } = useTranslation();
    const width = cn('mx-auto w-full px-4 sm:px-6', isWide ? 'max-w-6xl' : 'max-w-3xl');

    return (
        <div className="flex min-h-screen flex-col bg-bg font-sans text-text">
            <SkipToContent />
            <header className="relative overflow-hidden border-b-4 border-cta bg-brand-deep text-primary-contrast">
                <HexagonPattern className="absolute -right-6 -top-8 h-28 w-28 text-brand-cyan/40 sm:right-10 sm:h-36 sm:w-36" />
                <div className={cn(width, 'relative py-4')}>
                    <p className="font-display text-base font-extrabold uppercase tracking-widest sm:text-lg">{organizationName}</p>
                </div>
            </header>
            <main id="contenido" tabIndex={-1} className={cn(width, 'flex-1 py-8 sm:py-12')}>
                {children}
            </main>
            <footer className="relative overflow-hidden bg-brand-deep text-primary-contrast/80">
                <HexagonPattern className="absolute -bottom-10 -left-6 h-28 w-28 text-brand/60 sm:left-10" />
                <div className={cn(width, 'relative py-6 text-sm')}>{t('layout.footer')}</div>
            </footer>
        </div>
    );
}
