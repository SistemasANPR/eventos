import { fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';

import { CategoryFilter } from '@/components/molecules/CategoryFilter';
import { createTranslate } from '@/lib/translate';

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate({ program: { filter_label: 'Filtrar por categoría' } }), locale: 'es' }),
}));

const categories = [
    { id: 'c1', name: 'Talleres' },
    { id: 'c2', name: 'Conferencias' },
];

describe('CategoryFilter', () => {
    it('CA-029-21: renders one toggle button per category inside a labelled group, none pressed by default', () => {
        render(<CategoryFilter categories={categories} selectedId={null} onSelect={() => undefined} />);

        expect(screen.getByRole('group', { name: 'Filtrar por categoría' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Talleres' })).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getByRole('button', { name: 'Conferencias' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('CA-029-21: marks the selected category as pressed', () => {
        render(<CategoryFilter categories={categories} selectedId="c2" onSelect={() => undefined} />);

        expect(screen.getByRole('button', { name: 'Conferencias' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Talleres' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('CA-029-21: selecting a category calls onSelect with it and pressing it again clears the filter', () => {
        const onSelect = vi.fn();
        const { rerender } = render(<CategoryFilter categories={categories} selectedId={null} onSelect={onSelect} />);

        fireEvent.click(screen.getByRole('button', { name: 'Talleres' }));
        expect(onSelect).toHaveBeenLastCalledWith('c1');

        rerender(<CategoryFilter categories={categories} selectedId="c1" onSelect={onSelect} />);
        fireEvent.click(screen.getByRole('button', { name: 'Talleres' }));
        expect(onSelect).toHaveBeenLastCalledWith(null);
    });

    it('CA-029-21: is operable with the keyboard: native buttons, in the tab order, with a visible focus ring and a 44 px target', () => {
        render(<CategoryFilter categories={categories} selectedId={null} onSelect={() => undefined} />);

        for (const button of screen.getAllByRole('button')) {
            // Un <button type="button"> nativo se enfoca con Tab y se activa con Enter y Espacio sin código propio.
            expect(button.tagName).toBe('BUTTON');
            expect(button).toHaveAttribute('type', 'button');
            expect(button).not.toHaveAttribute('tabindex', '-1');
            expect(button).toBeEnabled();
            expect(button.className).toContain('focus-visible:outline');
            expect(button.className).toContain('min-h-11');
        }

        screen.getByRole('button', { name: 'Talleres' }).focus();
        expect(screen.getByRole('button', { name: 'Talleres' })).toHaveFocus();
    });

    it('CA-029-21: a controlled parent that filters shows all again when the pressed category is released', () => {
        function Harness() {
            const [selected, setSelected] = useState<string | null>(null);

            return (
                <>
                    <CategoryFilter categories={categories} selectedId={selected} onSelect={setSelected} />
                    <output data-testid="selected">{selected ?? 'all'}</output>
                </>
            );
        }

        render(<Harness />);

        fireEvent.click(screen.getByRole('button', { name: 'Talleres' }));
        expect(screen.getByTestId('selected')).toHaveTextContent('c1');
        expect(screen.getByRole('button', { name: 'Talleres' })).toHaveAttribute('aria-pressed', 'true');

        fireEvent.click(screen.getByRole('button', { name: 'Conferencias' }));
        expect(screen.getByRole('button', { name: 'Conferencias' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'Talleres' })).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(screen.getByRole('button', { name: 'Conferencias' }));
        expect(screen.getByTestId('selected')).toHaveTextContent('all');
    });

    it('renders nothing without categories', () => {
        const { container } = render(<CategoryFilter categories={[]} selectedId={null} onSelect={() => undefined} />);

        expect(container).toBeEmptyDOMElement();
    });
});
