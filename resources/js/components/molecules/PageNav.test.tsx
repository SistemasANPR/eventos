import { render, screen, within } from '@testing-library/react';

import { PageNav } from '@/components/molecules/PageNav';
import { createTranslate } from '@/lib/translate';

const translations = {
    pagination: { previous: 'Página anterior', next: 'Página siguiente', page_of: 'Página :current de :last' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

describe('PageNav', () => {
    it('CA-029-8: shows the previous and next links with the position inside a labelled navigation', () => {
        render(<PageNav label="Páginas de ponentes" page={{ currentPage: 2, lastPage: 3, prevUrl: '/p', nextUrl: '/p?page=3' }} />);

        const navigation = screen.getByRole('navigation', { name: 'Páginas de ponentes' });

        expect(within(navigation).getByRole('link', { name: 'Página anterior' })).toHaveAttribute('href', '/p');
        expect(within(navigation).getByRole('link', { name: 'Página anterior' })).toHaveAttribute('rel', 'prev');
        expect(within(navigation).getByRole('link', { name: 'Página siguiente' })).toHaveAttribute('href', '/p?page=3');
        expect(within(navigation).getByRole('link', { name: 'Página siguiente' })).toHaveAttribute('rel', 'next');
        expect(within(navigation).getByText('Página 2 de 3')).toBeInTheDocument();
    });

    it('CA-029-8: leaves out the previous link on the first page and the next link on the last one', () => {
        const { rerender } = render(<PageNav label="Páginas" page={{ currentPage: 1, lastPage: 2, prevUrl: null, nextUrl: '/p?page=2' }} />);

        expect(screen.queryByRole('link', { name: 'Página anterior' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Página siguiente' })).toBeInTheDocument();

        rerender(<PageNav label="Páginas" page={{ currentPage: 2, lastPage: 2, prevUrl: '/p', nextUrl: null }} />);

        expect(screen.getByRole('link', { name: 'Página anterior' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Página siguiente' })).not.toBeInTheDocument();
    });

    it('does not draw anything when everything fits in one page', () => {
        const { container } = render(<PageNav label="Páginas" page={{ currentPage: 1, lastPage: 1, prevUrl: null, nextUrl: null }} />);

        expect(container).toBeEmptyDOMElement();
    });
});
