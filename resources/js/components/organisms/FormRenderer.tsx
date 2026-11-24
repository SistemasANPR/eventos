import type { ReactNode, SyntheticEvent } from 'react';

import { FormFieldInput } from '@/components/molecules/FormFieldInput';
import { BUTTON_PRIMARY, FOCUS_RING, SECTION_CARD, SECTION_HEADING } from '@/lib/classes';
import { activeFields } from '@/lib/form-text';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { FormField } from '@/types/form';
import type { AnswerValue } from '@/types/submission';

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
    /** Título de la tarjeta que agrupa los campos de la definición; sin él, los campos van sueltos. */
    fieldsTitle?: string;
    /** Contenido dentro del `<form>`, antes de los campos de la definición (bloque fijo del envío público). */
    leading?: ReactNode;
    /** Contenido dentro del `<form>`, entre los campos y el botón de envío (consentimiento, anti-robots). */
    trailing?: ReactNode;
    /** Solo `public`: mientras se envía, el botón se desactiva. */
    isSubmitting?: boolean;
    /** Solo `public`: texto del botón; por defecto `forms.renderer.submit`. */
    submitLabel?: string;
    /** Solo `public`: desactiva el envío (p. ej. la convocatoria ya cerró). */
    isSubmitDisabled?: boolean;
    /** Valores con que arrancan los controles por clave de campo (edición de una propuesta). */
    initialValues?: Record<string, AnswerValue>;
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
    leading,
    trailing,
    fieldsTitle,
    isSubmitting = false,
    submitLabel,
    isSubmitDisabled = false,
    initialValues = {},
}: FormRendererProps) {
    const visibleFields = activeFields(fields);
    const isPreview = mode === 'preview';

    const handleSubmit = (event: SyntheticEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!isPreview && onSubmit !== undefined) {
            onSubmit(new FormData(event.currentTarget));
        }
    };

    if (visibleFields.length === 0 && leading === undefined && trailing === undefined) {
        return <p className="text-base text-text-muted">{t('forms.renderer.empty')}</p>;
    }

    const fieldInputs = visibleFields.map((field) => (
        <FormFieldInput
            key={field.key}
            field={field}
            locale={locale}
            defaultLocale={defaultLocale}
            idPrefix={idPrefix}
            t={t}
            error={errors[field.key]}
            initialValue={initialValues[field.key]}
        />
    ));

    return (
        <form aria-label={t('forms.renderer.form_label')} noValidate onSubmit={handleSubmit} className={cn('space-y-6', className)}>
            {leading}
            {fieldsTitle !== undefined && visibleFields.length > 0 ? (
                <section aria-labelledby={`${idPrefix}-fields`} className={cn(SECTION_CARD, 'space-y-6')}>
                    <h2 id={`${idPrefix}-fields`} className={SECTION_HEADING}>
                        {fieldsTitle}
                    </h2>
                    {fieldInputs}
                </section>
            ) : (
                fieldInputs
            )}
            {trailing}
            <div data-form-submit className="space-y-2">
                <button
                    type="submit"
                    disabled={isPreview || isSubmitting || isSubmitDisabled}
                    aria-busy={isSubmitting ? true : undefined}
                    className={cn(BUTTON_PRIMARY, FOCUS_RING, 'w-full sm:w-auto')}
                >
                    {submitLabel ?? t('forms.renderer.submit')}
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
