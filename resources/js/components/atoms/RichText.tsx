import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface RichTextProps {
    /** HTML que el servidor ya sanitizó. Aun así, el cliente no confía en él. */
    html: string;
    className?: string;
}

const FORMATTING_TAGS = ['p', 'strong', 'em', 'ul', 'ol', 'li'] as const;
type FormattingTag = (typeof FORMATTING_TAGS)[number];

/** Elementos cuyo contenido jamás se muestra, ni siquiera como texto. */
const DROPPED_WITH_CONTENT = new Set([
    'script',
    'style',
    'iframe',
    'object',
    'embed',
    'template',
    'noscript',
    'svg',
    'math',
    'head',
    'title',
    'textarea',
    'select',
    'frame',
    'frameset',
    'applet',
]);

const LINK_REL = 'noopener noreferrer nofollow';

/**
 * Muestra texto enriquecido (párrafos, énfasis, listas y enlaces) construyendo
 * elementos de React desde una lista blanca, sin inyectar HTML en el DOM.
 * Defensa en profundidad: el HTML se analiza en un documento inerte (`DOMParser`),
 * se descartan etiquetas y atributos no permitidos y el texto se renderiza siempre
 * como nodo de texto. Solo `href` http/https sobrevive, en enlaces con `rel` seguro.
 * Usar para contenido redactado por organizadores (p. ej. el aviso de privacidad);
 * no para HTML arbitrario ni para contenido que necesite imágenes o tablas.
 */
export function RichText({ html, className }: RichTextProps) {
    const body = new DOMParser().parseFromString(html, 'text/html').body;

    return (
        <div
            className={cn(
                'flex flex-col gap-4 text-base leading-relaxed text-text [&_ol]:list-decimal [&_ol]:pl-6 [&_ul]:list-disc [&_ul]:pl-6 [&_li]:mt-1 [&_ol_ol]:mt-1 [&_ul_ul]:mt-1',
                className,
            )}
        >
            {convertChildren(body, '0')}
        </div>
    );
}

function convertChildren(parent: Node, keyPrefix: string): ReactNode[] {
    return Array.from(parent.childNodes).map((node, index) => convertNode(node, `${keyPrefix}.${index}`));
}

function convertNode(node: Node, key: string): ReactNode {
    if (node.nodeType === Node.TEXT_NODE) {
        return node.textContent;
    }

    if (!(node instanceof Element)) {
        return null;
    }

    const tag = node.tagName.toLowerCase();

    if (DROPPED_WITH_CONTENT.has(tag)) {
        return null;
    }

    const children = convertChildren(node, key);

    if (tag === 'br') {
        return <br key={key} />;
    }

    if (tag === 'a') {
        return renderLink(node, key, children);
    }

    if (isFormattingTag(tag)) {
        return renderFormatting(tag, key, children);
    }

    // Etiqueta desconocida: se conserva solo su texto.
    return children;
}

function renderFormatting(tag: FormattingTag, key: string, children: ReactNode[]): ReactNode {
    switch (tag) {
        case 'p':
            return <p key={key}>{children}</p>;
        case 'strong':
            return <strong key={key}>{children}</strong>;
        case 'em':
            return <em key={key}>{children}</em>;
        case 'ul':
            return <ul key={key}>{children}</ul>;
        case 'ol':
            return <ol key={key}>{children}</ol>;
        case 'li':
            return <li key={key}>{children}</li>;
    }
}

function renderLink(element: Element, key: string, children: ReactNode[]): ReactNode {
    const href = safeHref(element.getAttribute('href'));

    if (href === null) {
        return children;
    }

    return (
        <a
            key={key}
            href={href}
            rel={LINK_REL}
            className="text-accent underline underline-offset-4 break-words focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
            {children}
        </a>
    );
}

/** Devuelve la URL normalizada solo si es absoluta y http o https. */
function safeHref(value: string | null): string | null {
    if (value === null) {
        return null;
    }

    try {
        const url = new URL(value.trim());

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}

function isFormattingTag(tag: string): tag is FormattingTag {
    return (FORMATTING_TAGS as readonly string[]).includes(tag);
}
