import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface AcceptanceFieldProps {
    id: string;
    isChecked: boolean;
    onChange: (isChecked: boolean) => void;
    /** Texto de la casilla; puede incluir un enlace. */
    children: ReactNode;
    error?: string;
    className?: string;
}

/**
 * Casilla de aceptación controlada, con su texto y su error asociados (`aria-describedby`,
 * `aria-invalid`). Usar para el consentimiento y las declaraciones del envío público; los
 * checkbox definidos por el organizador los dibuja `FormFieldInput`.
 */
export function AcceptanceField({ id, isChecked, onChange, children, error, className }: AcceptanceFieldProps) {
    const errorId = `${id}-error`;

    return (
        <div className={cn('space-y-2', className)}>
            <div className="flex items-start gap-3">
                <input
                    id={id}
                    type="checkbox"
                    checked={isChecked}
                    onChange={(event) => onChange(event.target.checked)}
                    aria-invalid={error !== undefined ? true : undefined}
                    aria-describedby={error !== undefined ? errorId : undefined}
                    className="mt-1 size-5 shrink-0 accent-primary"
                />
                <label htmlFor={id} className="min-h-11 text-base text-text">
                    {children}
                </label>
            </div>
            {error !== undefined ? (
                <p id={errorId} role="alert" className="text-sm font-medium text-error">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
