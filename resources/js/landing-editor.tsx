import '../css/landing-editor.css';

import { createRoot } from 'react-dom/client';

import { LandingEditor } from '@/components/organisms/landing-editor/LandingEditor';
import type { LandingEditorApi, LandingEditorProps } from '@/types/landing';

declare global {
    interface Window {
        /** Monta el editor de la landing en `element`; `api` conecta la persistencia (Livewire `$wire` en el panel). */
        mountLandingEditor?: (element: HTMLElement, api?: LandingEditorApi) => void;
        /** Alternativa a pasar `api` al montar: la toma `mountLandingEditor` si no recibe una. */
        landingEditorApi?: LandingEditorApi;
    }
}

/**
 * Punto de entrada del editor de la landing en el panel (plan 022, ADR-0010). Excepción acotada a
 * ADR-0004: el panel es de Filament y solo aquí monta React, con el mismo `LandingRenderer` del
 * sitio público como lienzo. Lee las props de `data-props` (JSON) y recibe el `api` de persistencia.
 */
export function mountLandingEditor(element: HTMLElement, api: LandingEditorApi | undefined = window.landingEditorApi): void {
    if (element.dataset.mounted === 'true') {
        return;
    }

    const props = readProps(element.dataset.props);

    if (props === null || api === undefined) {
        // Un editor sin datos o sin persistencia no debe aparentar funcionar: se deja ver el fallo.
        console.error('El editor de la landing no se montó: faltan las props o el api.');

        return;
    }

    element.dataset.mounted = 'true';
    createRoot(element).render(<LandingEditor {...props} api={api} />);
}

function readProps(raw: string | undefined): LandingEditorProps | null {
    try {
        const parsed: unknown = JSON.parse(raw ?? '');

        return isEditorProps(parsed) ? parsed : null;
    } catch {
        return null;
    }
}

function isEditorProps(value: unknown): value is LandingEditorProps {
    return (
        typeof value === 'object' &&
        value !== null &&
        'blocks' in value &&
        Array.isArray(value.blocks) &&
        'version' in value &&
        typeof value.version === 'number' &&
        'locales' in value &&
        Array.isArray(value.locales) &&
        'defaultLocale' in value &&
        typeof value.defaultLocale === 'string' &&
        'translations' in value &&
        typeof value.translations === 'object' &&
        'preview' in value &&
        typeof value.preview === 'object' &&
        'publicUrl' in value &&
        (value.publicUrl === null || typeof value.publicUrl === 'string')
    );
}

window.mountLandingEditor = mountLandingEditor;
