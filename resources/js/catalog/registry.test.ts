import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

import { registry, type ComponentLevel } from '@/catalog/registry';

const JS_ROOT = join(process.cwd(), 'resources/js');
const COMPONENTS_ROOT = join(JS_ROOT, 'components');

const LEVEL_BY_FOLDER: Record<string, ComponentLevel> = {
    atoms: 'atom',
    molecules: 'molecule',
    organisms: 'organism',
    templates: 'template',
    infrastructure: 'infrastructure',
};

function listFiles(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);
        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

function toPosix(path: string): string {
    return path.split('\\').join('/');
}

/** Archivos de componentes propios: `.tsx` de `components/` salvo `ui/` y pruebas. */
const componentFiles = listFiles(COMPONENTS_ROOT)
    .map((file) => toPosix(relative(COMPONENTS_ROOT, file)))
    .filter((file) => file.endsWith('.tsx') && !file.endsWith('.test.tsx') && !file.startsWith('ui/'));

const sourceFiles = listFiles(JS_ROOT).filter((file) => /\.(ts|tsx)$/.test(file) && !/\.test\.tsx?$/.test(file));

describe('component catalog', () => {
    it('has an entry for every component file', () => {
        const registered = registry.map((entry) => `${entry.path}.tsx`);

        expect(componentFiles.filter((file) => !registered.includes(file))).toEqual([]);
    });

    it('points every entry at an existing file', () => {
        const existing = componentFiles;

        expect(registry.map((entry) => `${entry.path}.tsx`).filter((file) => !existing.includes(file))).toEqual([]);
    });

    it('matches the level of each entry with its folder', () => {
        const wrong = registry.filter((entry) => LEVEL_BY_FOLDER[entry.path.split('/')[0] ?? ''] !== entry.level);

        expect(wrong.map((entry) => entry.name)).toEqual([]);
    });

    it('has no duplicate names', () => {
        const names = registry.map((entry) => entry.name);

        expect(new Set(names).size).toBe(names.length);
    });

    it('has no orphan component: someone imports each one by its direct path', () => {
        const orphans = registry.filter((entry) => {
            const importPath = `@/components/${entry.path}`;
            return !sourceFiles.some(
                (file) =>
                    !file.endsWith(`${entry.path}.tsx`) && readFileSync(file, 'utf8').includes(`'${importPath}'`),
            );
        });

        expect(orphans.map((entry) => entry.name)).toEqual([]);
    });

    it('requires an executable demo when the preview is a demo', () => {
        const withDemo = registry.filter((entry) => entry.preview === 'demo');
        const catalogDir = join(JS_ROOT, 'catalog');
        const demos = listFiles(catalogDir)
            .filter((file) => /demos-.*\.tsx$/.test(file))
            .map((file) => readFileSync(file, 'utf8'))
            .join('\n');

        expect(withDemo.filter((entry) => !demos.includes(entry.name)).map((entry) => entry.name)).toEqual([]);
    });
});
