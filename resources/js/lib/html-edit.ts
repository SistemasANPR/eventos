/** Resultado de aplicar un formato: el texto nuevo y la selección que debe quedar. */
export interface FormatResult {
    value: string;
    selectionStart: number;
    selectionEnd: number;
}

export type TextFormat = 'bold' | 'italic' | 'list' | 'link';

const WRAPPERS: Record<'bold' | 'italic', [string, string]> = {
    bold: ['<strong>', '</strong>'],
    italic: ['<em>', '</em>'],
};

/**
 * Aplica un formato al fragmento seleccionado de un texto con etiquetas (las que acepta el
 * servidor: p, strong, em, ul, ol, li y a). El servidor vuelve a sanear al guardar; esto solo
 * ahorra al organizador escribir las etiquetas.
 * `url` solo se usa con `link` y debe ser un `https` (el servidor rechaza lo demás).
 */
export function applyFormat(value: string, selectionStart: number, selectionEnd: number, format: TextFormat, url = ''): FormatResult {
    const before = value.slice(0, selectionStart);
    const selected = value.slice(selectionStart, selectionEnd);
    const after = value.slice(selectionEnd);

    if (format === 'list') {
        const items = selected
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== '')
            .map((line) => `<li>${line}</li>`)
            .join('');
        const html = `<ul>${items === '' ? '<li></li>' : items}</ul>`;

        return { value: `${before}${html}${after}`, selectionStart, selectionEnd: selectionStart + html.length };
    }

    const [open, close] = format === 'link' ? [`<a href="${url.replaceAll('"', '&quot;')}">`, '</a>'] : WRAPPERS[format];
    const html = `${open}${selected}${close}`;
    const caret = selectionStart + open.length;

    return {
        value: `${before}${html}${after}`,
        selectionStart: caret,
        selectionEnd: caret + selected.length,
    };
}

/** `true` si la dirección es un `https` absoluto (el único destino que acepta la landing, DA-8). */
export function isHttpsUrl(value: string): boolean {
    try {
        return new URL(value.trim()).protocol === 'https:';
    } catch {
        return false;
    }
}
