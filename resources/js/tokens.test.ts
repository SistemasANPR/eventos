import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

import { contrastRatio, TEXT_ON_BACKGROUND_PAIRS, UI_COMPONENT_PAIRS } from '@/lib/contrast';

/*
 * Guardiana de tokens (design.md §2.6 y §3): valida lo que se envía, no una copia.
 * Lee los valores reales de `resources/css/app.css` y la tabla de tokens de `design.md`.
 */
const ROOT = process.cwd();

function listFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);
        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

function readCssTokens(): Record<string, string> {
    const css = readFileSync(join(ROOT, 'resources/css/app.css'), 'utf8');
    const tokens: Record<string, string> = {};

    for (const [, name, value] of css.matchAll(/--color-([a-z-]+):\s*(#[0-9a-fA-F]{6});/g)) {
        if (name !== undefined && value !== undefined) {
            tokens[name] = value.toLowerCase();
        }
    }

    return tokens;
}

/** Tokens semánticos de `design.md` §3.1: filas `| `token` | `#hex` |` y las de estado (texto y fondo). */
function readDocumentedTokens(): Record<string, string> {
    const markdown = readFileSync(join(ROOT, 'design.md'), 'utf8');
    const tokens: Record<string, string> = {};

    for (const [, name, first, second] of markdown.matchAll(/^\| `([a-z-]+)` \| `(#[0-9a-fA-F]{6})`(?: \| `(#[0-9a-fA-F]{6})`)?/gm)) {
        if (name === undefined || first === undefined) {
            continue;
        }

        tokens[name] = first.toLowerCase();

        if (second !== undefined) {
            tokens[`${name}-bg`] = second.toLowerCase();
        }
    }

    return tokens;
}

const cssTokens = readCssTokens();

/**
 * Modo lista (design.md §2.6): pares conocidos que no cumplen, con el formato `texto on fondo`.
 * Se vacía al cerrar la deuda; un par nuevo que falle y no esté aquí rompe la prueba.
 */
const KNOWN_CONTRAST_VIOLATIONS: ReadonlySet<string> = new Set<string>([]);

function ratioOf(foreground: string, background: string): number {
    const fg = cssTokens[foreground];
    const bg = cssTokens[background];

    expect(fg, `falta el token --color-${foreground}`).toBeDefined();
    expect(bg, `falta el token --color-${background}`).toBeDefined();

    return contrastRatio(fg ?? '', bg ?? '');
}

describe('design tokens (AA)', () => {
    it.each(TEXT_ON_BACKGROUND_PAIRS)('CA-002-31: %s on %s reaches 4.5:1', (foreground, background) => {
        const ratio = ratioOf(foreground, background);

        if (!KNOWN_CONTRAST_VIOLATIONS.has(`${foreground} on ${background}`)) {
            expect(ratio).toBeGreaterThanOrEqual(4.5);
        }
    });

    it.each(UI_COMPONENT_PAIRS)('CA-002-31: %s on %s reaches 3:1 (UI components)', (foreground, background) => {
        const ratio = ratioOf(foreground, background);

        if (!KNOWN_CONTRAST_VIOLATIONS.has(`${foreground} on ${background}`)) {
            expect(ratio).toBeGreaterThanOrEqual(3);
        }
    });

    it('CA-002-31: the known violations list has no entry that already passes', () => {
        const stale = [...KNOWN_CONTRAST_VIOLATIONS].filter((pair) => {
            const [foreground = '', background = ''] = pair.split(' on ');
            const isText = TEXT_ON_BACKGROUND_PAIRS.some(([fg, bg]) => fg === foreground && bg === background);

            return ratioOf(foreground, background) >= (isText ? 4.5 : 3);
        });

        expect(stale).toEqual([]);
    });
});

describe('design tokens (design.md vs CSS)', () => {
    it('CA-002-31: every semantic token documented in design.md exists in the CSS with the same value', () => {
        const documented = readDocumentedTokens();
        const different = Object.entries(documented)
            .filter(([name, value]) => cssTokens[name] !== value)
            .map(([name, value]) => `${name}: design.md ${value}, css ${cssTokens[name] ?? 'ausente'}`);

        expect(Object.keys(documented).length).toBeGreaterThan(15);
        expect(different).toEqual([]);
    });

    it('CA-002-31: lime is never a text color (it is a background; the text over it is accent-contrast)', () => {
        const sources = listFiles(join(ROOT, 'resources/js'))
            .filter((file) => /\.(ts|tsx)$/.test(file) && !/\.test\.tsx?$/.test(file))
            .filter((file) => !file.split('\\').join('/').includes('/components/ui/'));

        const offenders = sources.filter((file) => /\btext-accent(?![-\w])/.test(readFileSync(file, 'utf8')));

        expect(offenders).toEqual([]);
    });
});
