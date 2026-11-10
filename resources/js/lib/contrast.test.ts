import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { contrastRatio, TEXT_ON_BACKGROUND_PAIRS, UI_COMPONENT_PAIRS } from '@/lib/contrast';

/** Lee los tokens reales del CSS: la prueba valida lo que se envía, no una copia. */
function readTokens(): Record<string, string> {
    const css = readFileSync(join(process.cwd(), 'resources/css/app.css'), 'utf8');
    const tokens: Record<string, string> = {};

    for (const match of css.matchAll(/--color-([a-z-]+):\s*(#[0-9a-fA-F]{6});/g)) {
        const [, name, value] = match;
        if (name !== undefined && value !== undefined) {
            tokens[name] = value;
        }
    }

    return tokens;
}

describe('contrastRatio', () => {
    it('is 21 between black and white', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
    });

    it('is 1 between a color and itself', () => {
        expect(contrastRatio('#1d4ed8', '#1d4ed8')).toBeCloseTo(1, 5);
    });

    it('is symmetric', () => {
        expect(contrastRatio('#1c1917', '#ffffff')).toBeCloseTo(contrastRatio('#ffffff', '#1c1917'), 10);
    });

    it('rejects a color that is not #rrggbb', () => {
        expect(() => contrastRatio('red', '#ffffff')).toThrow();
    });
});

describe('design tokens (AA)', () => {
    const tokens = readTokens();

    it.each(TEXT_ON_BACKGROUND_PAIRS)('%s on %s reaches 4.5:1', (foreground, background) => {
        const fg = tokens[foreground];
        const bg = tokens[background];

        expect(fg, `falta el token --color-${foreground}`).toBeDefined();
        expect(bg, `falta el token --color-${background}`).toBeDefined();
        expect(contrastRatio(fg ?? '', bg ?? '')).toBeGreaterThanOrEqual(4.5);
    });

    it.each(UI_COMPONENT_PAIRS)('%s on %s reaches 3:1', (foreground, background) => {
        const fg = tokens[foreground];
        const bg = tokens[background];

        expect(fg, `falta el token --color-${foreground}`).toBeDefined();
        expect(bg, `falta el token --color-${background}`).toBeDefined();
        expect(contrastRatio(fg ?? '', bg ?? '')).toBeGreaterThanOrEqual(3);
    });
});
