import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import * as classes from '@/lib/classes';

/*
 * Guardiana de clases compartidas (design.md §2.2, §2.6 y §3.2): las cadenas de
 * `lib/classes.ts` se importan, no se reescriben en línea; y una cadena larga de
 * clases que se repite entre archivos se extrae a `lib/classes.ts`.
 */
const JS_ROOT = join(process.cwd(), 'resources/js');

function listFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);
        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

const toPosix = (path: string): string => path.split('\\').join('/');

const sources = listFiles(JS_ROOT)
    .filter((file) => /\.(ts|tsx)$/.test(file) && !/\.test\.tsx?$/.test(file))
    .map((file) => ({ file: toPosix(relative(JS_ROOT, file)), text: readFileSync(file, 'utf8') }))
    .filter(({ file }) => !file.startsWith('components/ui/') && file !== 'lib/classes.ts');

/** Modo lista (design.md §2.6): cadenas repetidas que aún no se extraen. Se vacía al cerrar la deuda. */
const KNOWN_REPEATED_STRINGS: ReadonlySet<string> = new Set<string>([]);

const MIN_REPEATED_LENGTH = 40;

/** Cadenas que parecen clases de Tailwind: varias palabras sin signos de puntuación de texto. */
const LOOKS_LIKE_CLASSES = /^[\w:/[\]().%&>*-]+(?: [\w:/[\]().%&>*-]+){3,}$/;

describe('shared classes (lib/classes.ts)', () => {
    const shared = Object.entries(classes);

    it('CA-002-31: exports only non-empty strings', () => {
        expect(shared.length).toBeGreaterThan(0);
        expect(shared.filter(([, value]) => typeof value !== 'string' || value.trim() === '').map(([name]) => name)).toEqual([]);
    });

    it('CA-002-31: no file rewrites a shared string inline', () => {
        const offenders = shared.flatMap(([name, value]) =>
            sources.filter(({ text }) => text.includes(String(value))).map(({ file }) => `${file} reescribe ${name}`),
        );

        expect(offenders).toEqual([]);
    });

    it('CA-002-31: every shared string is used by at least two files (otherwise it is not shared)', () => {
        const unused = shared
            .filter(([name]) => sources.filter(({ text }) => new RegExp(`\\b${name}\\b`).test(text)).length < 2)
            .map(([name]) => name);

        expect(unused).toEqual([]);
    });

    it('CA-002-31: a long class string repeated across files is extracted to lib/classes.ts', () => {
        const filesByString = new Map<string, Set<string>>();

        for (const { file, text } of sources) {
            for (const [, , value] of text.matchAll(/(["'])([^"'\n]{40,})\1/g)) {
                if (value !== undefined && value.length >= MIN_REPEATED_LENGTH && LOOKS_LIKE_CLASSES.test(value)) {
                    filesByString.set(value, (filesByString.get(value) ?? new Set<string>()).add(file));
                }
            }
        }

        const repeated = [...filesByString]
            .filter(([value, files]) => files.size > 1 && !KNOWN_REPEATED_STRINGS.has(value))
            .map(([value, files]) => `${[...files].join(', ')}: ${value}`);

        expect(repeated).toEqual([]);
    });
});
