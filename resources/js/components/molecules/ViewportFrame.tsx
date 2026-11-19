import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

export type Viewport = 'mobile' | 'tablet' | 'desktop';

interface ViewportFrameProps {
    viewport: Viewport;
    /** Nombre accesible del marco (solo móvil y tablet). */
    title: string;
    children: ReactNode;
}

const FRAME_WIDTH: Record<Exclude<Viewport, 'desktop'>, number> = { mobile: 375, tablet: 768 };

/**
 * Muestra su contenido con el ancho de un móvil o una tablet. Las clases responsivas dependen del
 * ancho de la ventana y no del contenedor, así que móvil y tablet se dibujan dentro de un
 * `iframe` (con los mismos estilos de la página) para que el lienzo responda como lo haría en ese
 * dispositivo. En escritorio no hay marco. Usar en: `EditorCanvas`.
 */
export function ViewportFrame({ viewport, title, children }: ViewportFrameProps) {
    if (viewport === 'desktop') {
        return <>{children}</>;
    }

    return (
        <SizedFrame width={FRAME_WIDTH[viewport]} title={title}>
            {children}
        </SizedFrame>
    );
}

function SizedFrame({ width, title, children }: { width: number; title: string; children: ReactNode }) {
    const [frame, setFrame] = useState<HTMLIFrameElement | null>(null);
    const [body, setBody] = useState<HTMLElement | null>(null);
    const [height, setHeight] = useState(640);

    const attach = useCallback((element: HTMLIFrameElement | null): void => setFrame(element), []);

    useEffect(() => {
        if (frame === null) {
            return;
        }

        const prepare = (): void => {
            const doc = frame.contentDocument;

            if (doc !== null && doc.body !== null) {
                setBody(prepareFrameDocument(doc));
            }
        };

        prepare();
        frame.addEventListener('load', prepare);

        return () => frame.removeEventListener('load', prepare);
    }, [frame]);

    useEffect(() => {
        if (body === null || typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(() => setHeight(Math.max(body.scrollHeight, 320)));
        observer.observe(body);

        return () => observer.disconnect();
    }, [body]);

    return (
        <>
            <iframe
                ref={attach}
                title={title}
                style={{ width, height, maxWidth: '100%' }}
                className="mx-auto block rounded-md border border-border bg-bg"
            />
            {body !== null && createPortal(children, body)}
        </>
    );
}

/** Deja el documento del marco listo: mismos estilos que la página y la base del lienzo. Devuelve su `body`. */
function prepareFrameDocument(doc: Document): HTMLElement {
    copyStyles(document, doc);
    doc.body.className = 'landing-editor-root bg-bg font-sans text-text';
    doc.body.style.margin = '0';
    doc.body.style.padding = '16px';

    return doc.body;
}

/** Copia las hojas de estilo de la página al documento del marco. */
function copyStyles(from: Document, to: Document): void {
    if (to.head.querySelector('[data-copied-styles]') !== null) {
        return;
    }

    from.querySelectorAll('link[rel="stylesheet"], style').forEach((node) => {
        const copy = node.cloneNode(true);

        if (copy instanceof HTMLElement) {
            copy.dataset.copiedStyles = 'true';
            to.head.appendChild(copy);
        }
    });
}
