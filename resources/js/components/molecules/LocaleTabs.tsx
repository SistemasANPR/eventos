import type { KeyboardEvent } from 'react';

import { FOCUS_RING } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';

interface LocaleTabsProps {
    locales: string[];
    active: string;
    onChange: (locale: string) => void;
    /** Idiomas con textos faltantes en el bloque abierto: llevan una marca con texto. */
    missing: string[];
    /** `id` del panel que controlan las pestañas. */
    panelId: string;
    t: Translate;
}

/**
 * Pestañas de idioma del evento (flechas para moverse, patrón WAI-ARIA de pestañas). Marca con
 * texto los idiomas a los que les faltan textos. Usar en: `BlockSettingsPanel`.
 */
export function LocaleTabs({ locales, active, onChange, missing, panelId, t }: LocaleTabsProps) {
    const handleKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number): void => {
        const step = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;

        if (step === 0) {
            return;
        }

        event.preventDefault();
        const next = locales[(index + step + locales.length) % locales.length];

        if (next !== undefined) {
            onChange(next);
            document.getElementById(`${panelId}-tab-${next}`)?.focus();
        }
    };

    return (
        <div role="tablist" aria-label={t('landing_editor.locale_tabs')} className="flex flex-wrap gap-1 border-b border-border">
            {locales.map((locale, index) => {
                const isActive = locale === active;

                return (
                    <button
                        key={locale}
                        id={`${panelId}-tab-${locale}`}
                        type="button"
                        role="tab"
                        aria-selected={isActive}
                        aria-controls={panelId}
                        tabIndex={isActive ? 0 : -1}
                        lang={locale}
                        onClick={() => onChange(locale)}
                        onKeyDown={(event) => handleKeyDown(event, index)}
                        className={cn(
                            'min-h-11 rounded-t-md border-b-2 px-4 text-base font-semibold sm:min-h-9',
                            FOCUS_RING,
                            isActive ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text',
                        )}
                    >
                        {t(`languages.${locale}`)}
                        {missing.includes(locale) && (
                            <span className="ml-2 text-sm font-normal text-warning">
                                <span aria-hidden="true">●</span>
                                <span className="sr-only">{t('landing_editor.locale_incomplete')}</span>
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
