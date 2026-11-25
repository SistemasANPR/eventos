import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import { createTranslate } from '@/lib/translate';
import Unavailable from '@/pages/Events/Unavailable';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    unavailable: {
        title: 'Este evento ya no está disponible',
        body: ':organization archivó este evento y sus páginas públicas ya no se muestran.',
        hint: 'Si llegaste desde un enlace guardado o desde un correo, ese enlace ya no está vigente.',
    },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }): ReactNode => (
        <>
            <title>{title}</title>
            {children}
        </>
    ),
}));

describe('Events/Unavailable page', () => {
    const props = { event: { name: 'Congreso Parques' }, organization: { name: 'Parques de México', slug: 'parques' } };

    it('CA-032-8: tells the visitor the event is no longer available, with the event and organization names only', () => {
        render(<Unavailable {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Este evento ya no está disponible' })).toBeInTheDocument();
        expect(screen.getByText('Congreso Parques')).toBeInTheDocument();
        expect(screen.getByText('Parques de México archivó este evento y sus páginas públicas ya no se muestran.')).toBeInTheDocument();
        expect(screen.getByText(/ese enlace ya no está vigente/)).toBeInTheDocument();
    });

    it('CA-032-9: offers no link to the event, its calls or its program', () => {
        render(<Unavailable {...props} />);

        // El único enlace de la página es el de «saltar al contenido» del diseño.
        const links = screen.queryAllByRole('link');
        expect(links.map((link) => link.getAttribute('href'))).toEqual(['#contenido']);
    });

    it('CA-032-16: is marked noindex and the title names the page, the event and the organization', () => {
        const { container } = render(<Unavailable {...props} />);

        expect(document.title).toBe('Este evento ya no está disponible · Congreso Parques · Parques de México');
        expect(container.ownerDocument.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
    });

    it('CA-032-17: has one level-one heading and a labelled main region', () => {
        render(<Unavailable {...props} />);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('article', { name: 'Este evento ya no está disponible' })).toBeInTheDocument();
        expect(screen.getByRole('main')).toBeInTheDocument();
    });
});
