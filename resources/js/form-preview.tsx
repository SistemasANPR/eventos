import '../css/form-preview.css';

import { createRoot } from 'react-dom/client';

import { FormRenderer } from '@/components/organisms/FormRenderer';
import { createTranslate } from '@/lib/translate';
import type { FormPreviewProps } from '@/types/form';

declare global {
    interface Window {
        mountFormPreview?: (element: HTMLElement) => void;
    }
}

/**
 * Punto de entrada de la vista previa de un formulario en el panel (plan 005, DA-4).
 * Excepción acotada a ADR-0004: el panel es de Filament y solo aquí monta React, con el
 * mismo `FormRenderer` del formulario público y el envío desactivado.
 */
export function mountFormPreview(element: HTMLElement): void {
    if (element.dataset.mounted === 'true') {
        return;
    }

    const props = readProps(element.dataset.props);

    if (props === null) {
        return;
    }

    element.dataset.mounted = 'true';
    createRoot(element).render(
        <div className="rounded-lg border border-border bg-surface p-4 text-text sm:p-6">
            <FormRenderer
                mode="preview"
                fields={props.fields}
                locale={props.locale}
                defaultLocale={props.defaultLocale}
                t={createTranslate(props.translations)}
                className="max-w-2xl"
            />
        </div>,
    );
}

function readProps(raw: string | undefined): FormPreviewProps | null {
    try {
        const parsed: unknown = JSON.parse(raw ?? '');

        return isPreviewProps(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

function isPreviewProps(value: unknown): value is FormPreviewProps {
    return (
        typeof value === 'object' &&
        value !== null &&
        'fields' in value &&
        Array.isArray(value.fields) &&
        'locale' in value &&
        typeof value.locale === 'string' &&
        'defaultLocale' in value &&
        typeof value.defaultLocale === 'string' &&
        'translations' in value &&
        typeof value.translations === 'object'
    );
}

window.mountFormPreview = mountFormPreview;
document.querySelectorAll<HTMLElement>('[data-form-preview]').forEach(mountFormPreview);
