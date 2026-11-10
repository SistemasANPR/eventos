import '../css/app.css';

import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

/** Punto de entrada del sitio público (Inertia, sin SSR en R0: plan 002, DA-4). */
void createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob<ResolvedComponent>(['./pages/**/*.tsx', '!./pages/**/*.test.tsx']);
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(`Página de Inertia no encontrada: ${name}`);
        }

        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
