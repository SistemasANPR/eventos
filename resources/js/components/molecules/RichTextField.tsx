import { useRef, useState } from 'react';

import { BUTTON_SECONDARY, FOCUS_RING, FORM_CONTROL } from '@/lib/classes';
import { applyFormat, isHttpsUrl, type TextFormat } from '@/lib/html-edit';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { LocalizedText } from '@/types/landing';

interface RichTextFieldProps {
    id: string;
    label: string;
    value: LocalizedText;
    locale: string;
    defaultLocale: string;
    onChange: (value: LocalizedText) => void;
    t: Translate;
    isRequired?: boolean;
    errors?: string[];
}

const TOOLBAR_BUTTON = 'min-h-11 px-3 text-sm sm:min-h-9';

/**
 * Texto con formato de un bloque, en el idioma de la pestaña activa: un área de texto con botones
 * de negrita, cursiva, lista y enlace. Escribe etiquetas simples (p, strong, em, ul, li, a); el
 * servidor las sanea al guardar y el lienzo muestra el resultado al instante.
 * Usar en: `BlockSettingsPanel`. No para texto sin formato (usar `LocalizedField`).
 */
export function RichTextField({
    id,
    label,
    value,
    locale,
    defaultLocale,
    onChange,
    t,
    isRequired = false,
    errors = [],
}: RichTextFieldProps) {
    const areaRef = useRef<HTMLTextAreaElement>(null);
    const [isLinkOpen, setIsLinkOpen] = useState(false);
    const [linkUrl, setLinkUrl] = useState('');

    const current = value[locale] ?? '';
    const fallback = locale === defaultLocale ? '' : (value[defaultLocale] ?? '');
    const isMissing = current.trim() === '';
    const isLinkValid = isHttpsUrl(linkUrl);
    const describedBy = [`${id}-hint`, errors.length > 0 ? `${id}-error` : null].filter(Boolean).join(' ');

    const commit = (next: string): void => onChange({ ...value, [locale]: next });

    const handleFormat = (format: TextFormat, url = ''): void => {
        const area = areaRef.current;

        if (area === null) {
            return;
        }

        const result = applyFormat(current, area.selectionStart, area.selectionEnd, format, url);
        commit(result.value);
        requestAnimationFrame(() => {
            area.focus();
            area.setSelectionRange(result.selectionStart, result.selectionEnd);
        });
    };

    const handleInsertLink = (): void => {
        if (!isLinkValid) {
            return;
        }

        handleFormat('link', linkUrl.trim());
        setIsLinkOpen(false);
        setLinkUrl('');
    };

    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <div role="toolbar" aria-label={t('landing_editor.format.toolbar')} className="flex flex-wrap gap-2">
                <button type="button" onClick={() => handleFormat('bold')} className={cn(BUTTON_SECONDARY, TOOLBAR_BUTTON, 'font-bold')}>
                    {t('landing_editor.format.bold')}
                </button>
                <button type="button" onClick={() => handleFormat('italic')} className={cn(BUTTON_SECONDARY, TOOLBAR_BUTTON, 'italic')}>
                    {t('landing_editor.format.italic')}
                </button>
                <button type="button" onClick={() => handleFormat('list')} className={cn(BUTTON_SECONDARY, TOOLBAR_BUTTON)}>
                    {t('landing_editor.format.list')}
                </button>
                <button
                    type="button"
                    aria-expanded={isLinkOpen}
                    onClick={() => setIsLinkOpen((open) => !open)}
                    className={cn(BUTTON_SECONDARY, TOOLBAR_BUTTON)}
                >
                    {t('landing_editor.format.link')}
                </button>
            </div>
            {isLinkOpen && (
                <div className="flex flex-col gap-2 rounded-md border border-border bg-bg p-3 sm:flex-row sm:items-end">
                    <div className="flex flex-1 flex-col gap-1">
                        <label htmlFor={`${id}-link`} className="text-sm font-medium text-text">
                            {t('landing_editor.format.link_url')}
                        </label>
                        <input
                            id={`${id}-link`}
                            type="url"
                            inputMode="url"
                            placeholder="https://"
                            value={linkUrl}
                            onChange={(event) => setLinkUrl(event.target.value)}
                            aria-describedby={`${id}-link-hint`}
                            className={FORM_CONTROL}
                        />
                        <p id={`${id}-link-hint`} className="text-sm text-text-muted">
                            {t('landing_editor.format.link_hint')}
                        </p>
                    </div>
                    <button type="button" disabled={!isLinkValid} onClick={handleInsertLink} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                        {t('landing_editor.format.link_insert')}
                    </button>
                </div>
            )}
            <p id={`${id}-hint`} className="text-sm text-text-muted">
                {t('landing_editor.format.hint')}
            </p>
            <textarea
                ref={areaRef}
                id={id}
                lang={locale}
                rows={8}
                required={isRequired}
                value={current}
                placeholder={fallback}
                onChange={(event) => commit(event.target.value)}
                aria-describedby={describedBy}
                aria-invalid={errors.length > 0 ? true : undefined}
                className={cn(FORM_CONTROL, 'font-mono text-sm')}
            />
            {isMissing && (isRequired || fallback !== '') && (
                <p data-testid="missing-text" className="text-sm text-warning">
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
