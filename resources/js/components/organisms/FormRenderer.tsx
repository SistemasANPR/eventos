import type { SyntheticEvent } from 'react';

import { FormFieldInput } from '@/components/molecules/FormFieldInput';
import { FOCUS_RING } from '@/lib/classes';
import { activeFields } from '@/lib/form-text';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { FormField } from '@/types/form';

interface FormRendererProps {
    fields: readonly FormField[];
    locale: string;
    defaultLocale: string;
    /** `preview` desactiva el envío; `public` lo entrega a `onSubmit`. El resto del HTML es idéntico. */
    mode: 'preview' | 'public';
    t: Translate;
    /** Errores del servidor por clave de campo. */
    errors?: Record<string, string>;
    /** Solo en modo `public`: recibe lo que la persona respondió. */
    onSubmit?: (answers: FormData) => void;
    idPrefix?: string;
    className?: string;
}

/**
 * Dibuja un formulario de propuesta a partir de su definición (plan 005, DA-4). Lo usan por
 * igual la vista previa del panel y el formulario público (plan 006): por eso el HTML de los
 * campos es el mismo y solo cambia el área de envío (`data-form-submit`). Ignora los campos
 * retirados y respeta el orden de `position`. Sin validación en el cliente: el servidor decide.
 */
export function FormRenderer({
    fields,
    locale,
    defaultLocale,
    mode,
    t,
    errors = {},
    onSubmit,
    idPrefix = 'form',
    className,
}: FormRendererProps) {
    const visibleFields = activeFields(fields);
    const isPreview = mode === 'preview';

    const handleSubmit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!isPreview && onSubmit !== undefined) {
            onSubmit(new FormData(event.currentTarget));
        }
    };

    if (visibleFields.length === 0) {
        return <p className="text-base text-text-muted">{t('forms.renderer.empty')}</p>;
    }

    return (
        <form
            aria-label={t('forms.renderer.form_label')}
            noValidate
            onSubmit={handleSubmit}
            className={cn('space-y-6', className)}
        >
            {visibleFields.map((field) => (
                <FormFieldInput
                    key={field.key}
                    field={field}
                    locale={locale}
                    defaultLocale={defaultLocale}
                    idPrefix={idPrefix}
                    t={t}
                    error={errors[field.key]}
                />
            ))}
            <div data-form-submit className="space-y-2">
                <button
                    type="submit"
                    disabled={isPreview}
                    className={cn('inline-flex min-h-11 items-center rounded-md bg-primary px-6 text-base font-semibold text-primary-contrast hover:bg-primary-hover disabled:opacity-60', FOCUS_RING)}
                >
                    {t('forms.renderer.submit')}
                </button>
                {isPreview ? (
                    <p role="note" className="text-sm text-text-muted">
                        {t('forms.renderer.preview_notice')}
                    </p>
                ) : null}
            </div>
        </form>
    );
}
