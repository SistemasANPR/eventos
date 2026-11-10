/** Contraste WCAG 2.x entre dos colores hexadecimales (`#rrggbb`). */
export function contrastRatio(foreground: string, background: string): number {
    const [lighter, darker] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);

    return ((lighter ?? 0) + 0.05) / ((darker ?? 0) + 0.05);
}

/** Pares de tokens (texto sobre fondo) que la prueba `tokens.test.ts` exige en AA (4,5:1). */
export const TEXT_ON_BACKGROUND_PAIRS: ReadonlyArray<readonly [string, string]> = [
    ['text', 'bg'],
    ['text', 'surface'],
    ['text-muted', 'bg'],
    ['text-muted', 'surface'],
    ['primary', 'bg'],
    ['primary', 'surface'],
    ['primary-contrast', 'primary'],
    ['primary-contrast', 'primary-hover'],
    ['accent-contrast', 'accent'],
    ['support', 'bg'],
    ['support', 'surface'],
    ['success', 'success-bg'],
    ['warning', 'warning-bg'],
    ['error', 'error-bg'],
    ['info', 'info-bg'],
    ['error', 'bg'],
];

/** Pares de componentes de interfaz (borde de campo sobre su fondo) que exigen 3:1 (WCAG 1.4.11). */
export const UI_COMPONENT_PAIRS: ReadonlyArray<readonly [string, string]> = [
    ['border-input', 'bg'],
    ['border-input', 'surface'],
];

function luminance(hex: string): number {
    const match = /^#([0-9a-f]{6})$/i.exec(hex);

    if (match === null || match[1] === undefined) {
        throw new Error(`Color hexadecimal inválido: ${hex}`);
    }

    const [red, green, blue] = [0, 2, 4].map((start) => {
        const channel = parseInt((match[1] ?? '').slice(start, start + 2), 16) / 255;
        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * (red ?? 0) + 0.7152 * (green ?? 0) + 0.0722 * (blue ?? 0);
}
