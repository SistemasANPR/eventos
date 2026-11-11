import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import { demos } from '@/catalog/demos';
import { registry } from '@/catalog/registry';
import Index from '@/pages/Components/Index';

// Sin textos reales: `t()` devuelve la clave, lo que basta para comprobar qué se pinta.
vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({
        t: (key: string) => (key.startsWith('catalog.demo_data.rich_text') ? '<p>texto <strong>demo</strong></p>' : key),
        locale: 'es',
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ children }: { children?: ReactNode }): ReactNode => <div data-testid="head">{children}</div>,
}));

describe('Components/Index page (component catalog)', () => {
    it('CA-002-30: lists every registry entry with its level, category, useWhen and avoidFor', () => {
        render(<Index />);

        for (const entry of registry) {
            const section = screen.getByRole('region', { name: entry.name });

            expect(within(section).getByRole('heading', { level: 2, name: entry.name })).toBeInTheDocument();
            expect(within(section).getByText(entry.useWhen)).toBeInTheDocument();
            expect(within(section).getByText(entry.avoidFor)).toBeInTheDocument();
            expect(within(section).getByText(entry.category)).toBeInTheDocument();
            expect(within(section).getByText(`catalog.level.${entry.level}`)).toBeInTheDocument();
        }
    });

    it('CA-002-30: renders the demo of every entry whose preview is a demo, without errors', () => {
        render(<Index />);

        const withDemo = registry.filter((entry) => entry.preview === 'demo');

        expect(withDemo.length).toBeGreaterThan(0);

        for (const entry of withDemo) {
            const demo = screen.getByTestId(`demo-${entry.name}`);

            expect(demo, `demo de ${entry.name}`).not.toBeEmptyDOMElement();
        }
    });

    it('CA-002-30: has a demo exactly for the entries whose preview is a demo', () => {
        const withDemo = registry.filter((entry) => entry.preview === 'demo').map((entry) => entry.name);

        expect(Object.keys(demos).sort()).toEqual([...withDemo].sort());
    });

    it('CA-002-29: asks search engines not to index the page', () => {
        render(<Index />);

        // React 19 eleva `<meta>` al `<head>` del documento.
        expect(document.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
    });
});
