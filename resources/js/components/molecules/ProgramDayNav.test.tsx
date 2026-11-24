import { render, screen, within } from '@testing-library/react';

import { ProgramDayNav } from '@/components/molecules/ProgramDayNav';
import { createTranslate } from '@/lib/translate';

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate({ program: { days_label: 'Días del programa' } }), locale: 'es' }),
}));

const days = ['2026-11-20', '2026-11-21', '2026-11-22'];

describe('ProgramDayNav', () => {
    it('CA-029-14: links every day of the program inside a labelled navigation', () => {
        render(<ProgramDayNav days={days} currentDay="2026-11-20" baseUrl="/parques/events/congreso/program" locale="es" />);

        const nav = screen.getByRole('navigation', { name: 'Días del programa' });
        const links = within(nav).getAllByRole('link');

        expect(links.map((link) => link.getAttribute('href'))).toEqual([
            '/parques/events/congreso/program?day=2026-11-20',
            '/parques/events/congreso/program?day=2026-11-21',
            '/parques/events/congreso/program?day=2026-11-22',
        ]);
    });

    it('CA-029-22: marks only the day being shown with aria-current', () => {
        render(<ProgramDayNav days={days} currentDay="2026-11-21" baseUrl="/p" locale="es" />);

        const links = screen.getAllByRole('link');

        expect(links.map((link) => link.getAttribute('aria-current'))).toEqual([null, 'page', null]);
    });

    it('CA-029-12: writes each day in the locale of the page without shifting it', () => {
        render(<ProgramDayNav days={days} currentDay="2026-11-20" baseUrl="/p" locale="en" />);

        expect(screen.getByRole('link', { name: /Nov 20/ })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Nov 22/ })).toBeInTheDocument();
    });

    it('keeps the language already in the base url', () => {
        render(<ProgramDayNav days={days} currentDay="2026-11-20" baseUrl="/p?lang=en" locale="en" />);

        expect(screen.getAllByRole('link')[1]).toHaveAttribute('href', '/p?lang=en&day=2026-11-21');
    });

    it('gives each link a touch target of at least 44 px and a visible focus ring', () => {
        render(<ProgramDayNav days={days} currentDay="2026-11-20" baseUrl="/p" locale="es" />);

        for (const link of screen.getAllByRole('link')) {
            expect(link.className).toContain('min-h-11');
            expect(link.className).toContain('focus-visible:outline');
        }
    });

    it('renders nothing when the program has a single day or none', () => {
        const { container, rerender } = render(<ProgramDayNav days={['2026-11-20']} currentDay="2026-11-20" baseUrl="/p" locale="es" />);

        expect(container).toBeEmptyDOMElement();

        rerender(<ProgramDayNav days={[]} currentDay={null} baseUrl="/p" locale="es" />);
        expect(container).toBeEmptyDOMElement();
    });
});
