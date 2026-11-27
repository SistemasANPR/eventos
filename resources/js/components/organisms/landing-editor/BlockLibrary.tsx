import { useId } from 'react';

import { BUTTON_SECONDARY, FOCUS_RING, HEADING_LG } from '@/lib/classes';
import { blockTypesFor, LANDING_LIMITS } from '@/lib/landing-blocks';
import { blockLabelKey } from '@/lib/landing-draft';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { LandingBlockType } from '@/types/landing';

interface BlockLibraryProps {
    /** Editor del evento o de una convocatoria: esta última suma las secciones de su plantilla (plan 033). */
    kind?: 'event' | 'call';
    /** Con la plantilla disponible (editor de una convocatoria), ofrece aplicarla; sin ella no hay botón. */
    onApplyTemplate?: () => void;
    /** `false` al llegar al máximo de bloques de la landing. */
    canAdd: boolean;
    onAdd: (type: LandingBlockType) => void;
    t: Translate;
}

/**
 * Biblioteca de bloques: un botón por tipo de la lista blanca, con su descripción. El bloque nuevo
 * se añade tras el seleccionado (o al final). Usar en: `LandingEditor`.
 */
export function BlockLibrary({ kind = 'event', onApplyTemplate, canAdd, onAdd, t }: BlockLibraryProps) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-3">
            <h2 id={headingId} className={HEADING_LG}>
                {t('landing_editor.library.title')}
            </h2>
            <p className="text-sm text-text-muted">
                {canAdd ? t('landing_editor.library.hint') : t('landing_editor.library.full', { max: String(LANDING_LIMITS.blocks) })}
            </p>
            {onApplyTemplate !== undefined && (
                <div className="flex flex-col gap-1">
                    <button type="button" onClick={onApplyTemplate} className={cn(BUTTON_SECONDARY, FOCUS_RING, 'w-full px-3 text-sm')}>
                        {t('landing_editor.library.template')}
                    </button>
                    <p className="text-sm text-text-muted">{t('landing_editor.library.template_hint')}</p>
                </div>
            )}
            <ul className="grid gap-2 sm:grid-cols-2">
                {blockTypesFor(kind).map((type) => (
                    <li key={type}>
                        <button
                            type="button"
                            disabled={!canAdd}
                            onClick={() => onAdd(type)}
                            className={cn(BUTTON_SECONDARY, FOCUS_RING, 'h-full w-full flex-col items-start gap-1 py-2 text-left')}
                        >
                            <span>{t(blockLabelKey(type))}</span>
                            <span className="text-sm font-normal text-text-muted">{t(`landing_editor.library.description.${type}`)}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </section>
    );
}
