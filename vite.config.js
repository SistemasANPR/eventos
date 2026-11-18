import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';

// Sin fuentes remotas: Montserrat y Roboto se sirven desde resources/fonts
// (no se envía la IP del visitante a un tercero; docs/privacidad.md).
export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/filament/admin/theme.css', 'resources/js/app.tsx', 'resources/js/form-preview.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        host: '0.0.0.0',
        origin: 'http://localhost:5173',
        // El plugin de Laravel solo permite `server.origin` en CORS; la página del panel vive en otro
        // puerto (8080) y sin esto el navegador bloquea los módulos de React (vista previa vacía).
        cors: {
            origin: [/^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/],
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
