import '../css/form-preview.css';

import { useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';

import { FormRenderer } from '@/components/organisms/FormRenderer';
import { SubmissionBlock } from '@/components/organisms/SubmissionBlock';
import { createTranslate } from '@/lib/translate';
import type { FormPreviewProps } from '@/types/form';
import type { Translations } from '@/types/inertia';
import type { ParticipantFieldConfig } from '@/types/submission';

declare global {
    interface Window {
        mountFormPreview?: (element: HTMLElement) => void;
        mountParticipantsPreview?: (element: HTMLElement) => void;
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

/** Datos de la vista previa de Participantes (`App\Support\Forms\FormPreviewProps::participants`). */
interface ParticipantsPreviewProps {
    locale: string;
    defaultLocale: string;
    maxParticipants: number;
    participantFields: ParticipantFieldConfig;
    translations: Translations;
}

/** Bloque de Participantes tal como lo ve quien envía una propuesta, con el límite de personas del formulario. */
function ParticipantsPreview({ props }: { props: ParticipantsPreviewProps }) {
    const t = useMemo(() => createTranslate(props.translations), [props.translations]);
    const [ids, setIds] = useState<number[]>([0]);
    const nextId = useRef(1);

    return (
        // Es solo una vista: va en su propio formulario (sin validar ni enviar) para que lo «obligatorio» de estos campos
        // no impida guardar el editor de Filament que la contiene.
        <form
            noValidate
            onSubmit={(event) => event.preventDefault()}
            className="rounded-lg border border-border bg-surface p-4 text-text sm:p-6"
        >
            <SubmissionBlock
                showDetails={false}
                participantConfig={props.participantFields}
                locale={props.locale}
                defaultLocale={props.defaultLocale}
                t={t}
                tracks={[]}
                minParticipants={1}
                maxParticipants={props.maxParticipants}
                participantIds={ids}
                onAddParticipant={() => setIds((current) => [...current, nextId.current++])}
                onRemoveParticipant={(id) => setIds((current) => current.filter((item) => item !== id))}
                errors={{}}
                idPrefix="preview"
            />
        </form>
    );
}

export function mountParticipantsPreview(element: HTMLElement): void {
    if (element.dataset.mounted === 'true') {
        return;
    }

    try {
        const parsed: unknown = JSON.parse(element.dataset.props ?? '');

        if (typeof parsed !== 'object' || parsed === null) {
            return;
        }

        element.dataset.mounted = 'true';
        createRoot(element).render(<ParticipantsPreview props={parsed as ParticipantsPreviewProps} />);
    } catch {
        // Sin datos válidos no se monta nada.
    }
}

window.mountFormPreview = mountFormPreview;
window.mountParticipantsPreview = mountParticipantsPreview;
document.querySelectorAll<HTMLElement>('[data-form-preview]').forEach(mountFormPreview);
document.querySelectorAll<HTMLElement>('[data-participants-preview]').forEach(mountParticipantsPreview);
