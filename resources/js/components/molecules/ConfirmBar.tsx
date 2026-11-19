import { useEffect, useId, useRef } from 'react';

import { BUTTON_PRIMARY, BUTTON_SECONDARY, FOCUS_RING } from '@/lib/classes';
import { cn } from '@/lib/utils';

interface ConfirmBarProps {
    title: string;
    description: string;
    confirmLabel: string;
    cancelLabel: string;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Confirmación en línea de una acción destructiva (quitar un bloque, descartar cambios). El foco
 * entra en «cancelar» (la opción segura) y Escape cancela.
 * Usar en: `LandingEditor`; no para avisos que no piden decidir.
 */
export function ConfirmBar({ title, description, confirmLabel, cancelLabel, onConfirm, onCancel }: ConfirmBarProps) {
    const titleId = useId();
    const descriptionId = useId();
    const cancelRef = useRef<HTMLButtonElement>(null);
    const cancelHandler = useRef(onCancel);

    useEffect(() => {
        cancelHandler.current = onCancel;
    });

    useEffect(() => {
        cancelRef.current?.focus();

        const handleEscape = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                cancelHandler.current();
            }
        };

        document.addEventListener('keydown', handleEscape);

        return () => document.removeEventListener('keydown', handleEscape);
    }, []);

    return (
        <div
            role="alertdialog"
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            className="flex flex-col gap-3 rounded-lg border border-error bg-error-bg p-4 text-error"
        >
            <p id={titleId} className="text-base font-semibold">
                {title}
            </p>
            <p id={descriptionId} className="text-base">
                {description}
            </p>
            <div className="flex flex-col gap-2 sm:flex-row">
                <button ref={cancelRef} type="button" onClick={onCancel} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                    {cancelLabel}
                </button>
                <button type="button" onClick={onConfirm} className={cn(BUTTON_PRIMARY, FOCUS_RING)}>
                    {confirmLabel}
                </button>
            </div>
        </div>
    );
}
