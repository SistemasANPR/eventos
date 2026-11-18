import { readFileSync } from 'node:fs';
import { join } from 'node:path';

/*
 * Guardiana del servidor de desarrollo de Vite: el plugin de Laravel usa `server.origin` como único
 * origen permitido de CORS, y el proyecto lo fija en `http://localhost:5173` (para que `public/hot` no
 * apunte a 0.0.0.0). Sin `server.cors` explícito, el navegador bloquea los módulos de React que pide
 * la página del panel en `http://localhost:8080` y la vista previa del formulario queda vacía.
 * Lee el patrón real de `vite.config.js`, no una copia.
 */
function readAllowedOrigin(): RegExp | null {
    const config = readFileSync(join(process.cwd(), 'vite.config.js'), 'utf8');
    const match = /cors:\s*\{\s*origin:\s*\[\s*\/(.+)\/([a-z]*)\s*,?\s*\]/.exec(config);

    return match?.[1] === undefined ? null : new RegExp(match[1], match[2]);
}

describe('vite dev server CORS', () => {
    it('declares an explicit server.cors origin pattern', () => {
        expect(readAllowedOrigin()).not.toBeNull();
    });

    it('allows the panel origin on any local port, so the React modules load from the dev server', () => {
        const pattern = readAllowedOrigin();

        for (const pageOrigin of ['http://localhost:8080', 'http://127.0.0.1:8080', 'http://localhost:5173']) {
            expect(pattern?.test(pageOrigin)).toBe(true);
        }
    });

    it('does not allow a foreign origin', () => {
        expect(readAllowedOrigin()?.test('https://example.com')).toBe(false);
    });
});
