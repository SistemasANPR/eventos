import type { ReactNode } from 'react';

import { SkipToContent } from '@/components/infrastructure/SkipToContent';
import { useTranslation } from '@/hooks/useTranslation';

interface PublicLayoutProps {
    organizationName: string;
    children: ReactNode;
}

/** Estructura de las páginas públicas: salto al contenido, cabecera, contenido principal y pie. */
export function PublicLayout({ organizationName, children }: PublicLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-screen flex-col bg-bg font-sans text-text">
            <SkipToContent />
            <header className="border-b border-border">
                <div className="mx-auto w-full max-w-3xl px-4 py-4 sm:px-6">
                    <p className="text-base font-semibold text-text">{organizationName}</p>
                </div>
            </header>
            <main id="contenido" tabIndex={-1} className="mx-auto w-full max-w-3xl flex-1 px-4 py-8 sm:px-6 sm:py-12">
                {children}
            </main>
            <footer className="border-t border-border">
                <div className="mx-auto w-full max-w-3xl px-4 py-4 text-sm text-text-muted sm:px-6">
                    {t('layout.footer')}
                </div>
            </footer>
        </div>
    );
}
