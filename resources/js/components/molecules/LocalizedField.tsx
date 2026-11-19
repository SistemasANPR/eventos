import { FORM_CONTROL } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import type { LocalizedText } from '@/types/landing';

interface LocalizedFieldProps {
    /** Prefijo único del campo: de él salen `id`, la ayuda y el error. */
    id: string;
    label: string;
    value: LocalizedText;
    /** Idioma que se está editando (pestaña activa). */
    locale: string;
    defaultLocale: string;
    onChange: (value: LocalizedText) => void;
    t: Translate;
    isMultiline?: boolean;
    isRequired?: boolean;
    /** Errores del servidor para este campo. */
    errors?: string[];
    hint?: string;
}

/**
 * Un texto de un bloque en el idioma de la pestaña activa. Avisa si falta en ese idioma (y el
 * visitante verá el del idioma base, DA-5) y muestra ese texto base como pista.
 * Usar en: `BlockSettingsPanel`. No para texto con formato (usar `RichTextField`).
 */
export function LocalizedField({
    id,
    label,
    value,
    locale,
    defaultLocale,
    onChange,
    t,
    isMultiline = false,
    isRequired = false,
    errors = [],
    hint,
}: LocalizedFieldProps) {
    const current = value[locale] ?? '';
    const isMissing = current.trim() === '';
    const fallback = locale === defaultLocale ? '' : (value[defaultLocale] ?? '');
    const describedBy = [
        hint === undefined ? null : `${id}-hint`,
        isMissing ? `${id}-missing` : null,
        errors.length > 0 ? `${id}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');

    const handleChange = (next: string): void => onChange({ ...value, [locale]: next });

    const shared = {
        id,
        value: current,
        lang: locale,
        required: isRequired,
        placeholder: fallback,
        'aria-describedby': describedBy === '' ? undefined : describedBy,
        'aria-invalid': errors.length > 0 ? true : undefined,
        className: FORM_CONTROL,
    };

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            {hint !== undefined && (
                <p id={`${id}-hint`} className="text-sm text-text-muted">
                    {hint}
                </p>
            )}
            {isMultiline ? (
                <textarea {...shared} rows={4} onChange={(event) => handleChange(event.target.value)} />
            ) : (
                <input {...shared} type="text" onChange={(event) => handleChange(event.target.value)} />
            )}
            {isMissing && (isRequired || fallback !== '') && (
                <p id={`${id}-missing`} data-testid="missing-text" className="text-sm text-warning">
                    {t('landing_editor.missing_text', { language: t(`languages.${locale}`) })}
                </p>
            )}
            {errors.length > 0 && (
                <p id={`${id}-error`} role="alert" className="text-sm font-medium text-error">
                    {errors.join(' ')}
                </p>
            )}
        </div>
    );
}
