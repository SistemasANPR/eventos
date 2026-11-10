import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/*
 * Guardianas de convenciones del frontend (design.md §2.6, resources/js/CLAUDE.md).
 * Leen el código fuente: una regla que se puede comprobar con una prueba no
 * depende de la revisión.
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
    .filter((file) => /\.(ts|tsx)$/.test(file) && !/\.test\.tsx?$/.test(file) && !toPosix(file).includes('/components/ui/'))
    .map((file) => ({ file: toPosix(relative(JS_ROOT, file)), text: readFileSync(file, 'utf8') }));

describe('frontend conventions', () => {
    it('uses export default only in pages/', () => {
        const offenders = sources.filter(({ file, text }) => !file.startsWith('pages/') && /^export default\b/m.test(text));

        expect(offenders.map(({ file }) => file)).toEqual([]);
    });

    it('does not use arbitrary hex colors in classes (use the semantic tokens)', () => {
        const offenders = sources.filter(({ text }) => /\[#[0-9a-fA-F]{3,8}\]/.test(text));

        expect(offenders.map(({ file }) => file)).toEqual([]);
    });

    it('does not use framework color classes outside the tokens (bg-gray-900, text-red-500...)', () => {
        const palette = /\b(?:bg|text|border|ring|outline|fill|stroke)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3}\b/;
        const offenders = sources.filter(({ text }) => palette.test(text));

        expect(offenders.map(({ file }) => file)).toEqual([]);
    });

    it('does not use dangerouslySetInnerHTML', () => {
        const offenders = sources.filter(({ text }) => text.includes('dangerouslySetInnerHTML'));

        expect(offenders.map(({ file }) => file)).toEqual([]);
    });

    it('does not expose secrets through public bundler variables', () => {
        const offenders = sources.filter(({ text }) => /VITE_[A-Z_]*(?:SECRET|KEY|TOKEN|PASSWORD)/.test(text));

        expect(offenders.map(({ file }) => file)).toEqual([]);
    });
});
