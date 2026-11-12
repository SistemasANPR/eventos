import type { ReactNode } from 'react';

import { COUNTRY_CODES } from '@/lib/countries';
import { FORM_CONTROL } from '@/lib/classes';
import { configOf, pickText } from '@/lib/form-text';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { FormField } from '@/types/form';

interface FormFieldInputProps {
    field: FormField;
    locale: string;
    defaultLocale: string;
    /** Prefijo de los `id` (deterministas, para que dos renderizados produzcan el mismo HTML). */
    idPrefix: string;
    t: Translate;
    /** Mensaje de error del servidor para este campo. */
    error?: string;
    className?: string;
}

/**
 * Un campo de un formulario de propuesta según su definición: etiqueta, ayuda, control y
 * error asociados por `for`, `aria-describedby` y `aria-invalid`. Los controles no tienen
 * estado propio (el formulario los lee al enviar). Usar solo dentro de `FormRenderer`.
 */
export function FormFieldInput({ field, locale, defaultLocale, idPrefix, t, error, className }: FormFieldInputProps) {
    const id = `${idPrefix}-${field.key}`;
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const label = pickText(field.label, locale, defaultLocale);
    const help = pickText(field.help, locale, defaultLocale);
    const describedBy = [help !== '' ? helpId : null, error !== undefined ? errorId : null].filter(Boolean).join(' ');

    const shared = {
        id,
        name: field.key,
        required: field.required,
        'aria-describedby': describedBy === '' ? undefined : describedBy,
        'aria-invalid': error !== undefined ? true : undefined,
    };

    const caption = (
        <>
            {label}
            {field.required ? <span className="font-normal text-text-muted"> ({t('forms.renderer.required')})</span> : null}
        </>
    );

    return (
        <div className={cn('space-y-2', className)}>
            {isGroup(field) ? (
                <fieldset aria-describedby={shared['aria-describedby']} className="space-y-2">
                    <legend className="block text-base font-medium text-text">{caption}</legend>
                    {renderHelp(help, helpId)}
                    {renderGroup(field, shared.id, locale, defaultLocale)}
                    {renderError(error, errorId)}
                </fieldset>
            ) : (
                <>
                    {field.type === 'checkbox' ? null : (
                        <label htmlFor={id} className="block text-base font-medium text-text">
                            {caption}
                        </label>
                    )}
                    {renderControl(field, shared, caption, locale, defaultLocale, t)}
                    {renderHelp(help, helpId)}
                    {renderError(error, errorId)}
                </>
            )}
        </div>
    );
}

type SharedControlProps = {
    id: string;
    name: string;
    required: boolean;
    'aria-describedby': string | undefined;
    'aria-invalid': boolean | undefined;
};

function isGroup(field: FormField): boolean {
    return field.type === 'single_choice' || field.type === 'multiple_choice';
}

function renderHelp(help: string, id: string): ReactNode {
    return help === '' ? null : (
        <p id={id} className="text-sm text-text-muted">
            {help}
        </p>
    );
}

function renderError(error: string | undefined, id: string): ReactNode {
    return error === undefined ? null : (
        <p id={id} role="alert" className="text-sm font-medium text-error">
            {error}
        </p>
    );
}

function renderGroup(field: FormField, id: string, locale: string, defaultLocale: string): ReactNode {
    const inputType = field.type === 'single_choice' ? 'radio' : 'checkbox';

    return (configOf(field).options ?? []).map((option) => (
        <div key={option.value} className="flex min-h-11 items-center gap-3">
            <input
                id={`${id}-${option.value}`}
                type={inputType}
                name={field.type === 'multiple_choice' ? `${field.key}[]` : field.key}
                value={option.value}
                required={field.type === 'single_choice' && field.required}
                className="size-5 accent-primary"
            />
            <label htmlFor={`${id}-${option.value}`} className="text-base text-text">
                {pickText(option.label, locale, defaultLocale)}
            </label>
        </div>
    ));
}

function renderControl(
    field: FormField,
    shared: SharedControlProps,
    caption: ReactNode,
    locale: string,
    defaultLocale: string,
    t: Translate,
): ReactNode {
    const config = configOf(field);

    switch (field.type) {
        case 'short_text':
            return <input {...shared} type="text" minLength={config.minLength} maxLength={config.maxLength} className={FORM_CONTROL} />;
        case 'long_text':
            return <textarea {...shared} rows={5} minLength={config.minLength} maxLength={config.maxLength} className={FORM_CONTROL} />;
        case 'number':
            return <input {...shared} type="number" step="any" min={config.min} max={config.max} className={FORM_CONTROL} />;
        case 'date':
            return <input {...shared} type="date" className={FORM_CONTROL} />;
        case 'checkbox':
            return (
                <div className="flex min-h-11 items-center gap-3">
                    <input {...shared} type="checkbox" className="size-5 accent-primary" />
                    <label htmlFor={shared.id} className="text-base text-text">
                        {caption}
                    </label>
                </div>
            );
        case 'country':
            return (
                <select {...shared} defaultValue="" className={FORM_CONTROL}>
                    <option value="">{t('forms.renderer.select_country')}</option>
                    {countryOptions(locale).map(([code, name]) => (
                        <option key={code} value={code}>
                            {name}
                        </option>
                    ))}
                </select>
            );
        case 'file':
            return (
                <>
                    <input
                        {...shared}
                        type="file"
                        accept={(config.accept ?? []).map((extension) => `.${extension}`).join(',') || undefined}
                        multiple={(config.maxFiles ?? 1) > 1}
                        className={FORM_CONTROL}
                    />
                    {renderFileHints(config.accept, config.maxFiles, t)}
                </>
            );
        default:
            return null;
    }
}

function renderFileHints(accept: string[] | undefined, maxFiles: number | undefined, t: Translate): ReactNode {
    const hints = [
        accept !== undefined && accept.length > 0 ? t('forms.renderer.file_types', { types: accept.join(', ') }) : null,
        maxFiles !== undefined && maxFiles > 1 ? t('forms.renderer.file_max', { max: String(maxFiles) }) : null,
    ].filter((hint): hint is string => hint !== null);

    return hints.length === 0 ? null : <p className="text-sm text-text-muted">{hints.join(' · ')}</p>;
}

/** Países con su nombre en el idioma pedido (Intl), ordenados por ese nombre. */
function countryOptions(locale: string): Array<[string, string]> {
    const names = new Intl.DisplayNames([locale], { type: 'region' });

    return COUNTRY_CODES.map((code): [string, string] => [code, names.of(code) ?? code]).sort((a, b) =>
        a[1].localeCompare(b[1], locale),
    );
}
