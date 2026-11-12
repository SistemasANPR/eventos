import { fireEvent, render, screen, within } from '@testing-library/react';

import { FormRenderer } from '@/components/organisms/FormRenderer';
import { createTranslate } from '@/lib/translate';
import type { FormField, FormFieldOption } from '@/types/form';

const t = createTranslate({
    forms: {
        renderer: {
            required: 'obligatorio',
            select_country: 'Selecciona un país',
            file_types: 'Tipos permitidos: :types',
            file_max: 'Máximo de archivos: :max',
            submit: 'Enviar propuesta',
            preview_notice: 'Vista previa: el envío está desactivado.',
            empty: 'Sin campos',
            form_label: 'Formulario de propuesta',
        },
    },
});

function field(type: FormField['type'], overrides: Partial<FormField> = {}): FormField {
    return {
        key: `f_${type}`,
        type,
        required: false,
        position: 1,
        identifiesAuthor: false,
        label: { es: `Etiqueta ${type}`, en: `Label ${type}` },
        help: [],
        config: [],
        retiredAt: null,
        ...overrides,
    };
}

const OPTIONS: FormFieldOption[] = [
    { value: 'o_1', label: { es: 'Ciencia', en: 'Science' } },
    { value: 'o_2', label: { es: 'Arte' } },
];

const ALL_TYPES: FormField[] = [
    field('short_text', { position: 1, config: { maxLength: 200 } }),
    field('long_text', { position: 2 }),
    field('number', { position: 3, config: { min: 0, max: 10 } }),
    field('single_choice', { position: 4, config: { options: OPTIONS } }),
    field('multiple_choice', { position: 5, config: { options: OPTIONS, maxSelections: 2 } }),
    field('checkbox', { position: 6 }),
    field('date', { position: 7 }),
    field('country', { position: 8 }),
    field('file', { position: 9, config: { accept: ['pdf', 'pptx'], maxFiles: 2 } }),
];

function renderForm(fields: FormField[], props: Partial<Parameters<typeof FormRenderer>[0]> = {}) {
    return render(<FormRenderer fields={fields} locale="es" defaultLocale="es" mode="preview" t={t} {...props} />);
}

describe('FormRenderer', () => {
    it('CA-005-15: renders the nine field types with an accessible name each', () => {
        renderForm(ALL_TYPES);

        expect(screen.getByLabelText(/Etiqueta short_text/)).toHaveAttribute('type', 'text');
        expect(screen.getByLabelText(/Etiqueta long_text/).tagName).toBe('TEXTAREA');
        expect(screen.getByLabelText(/Etiqueta number/)).toHaveAttribute('type', 'number');
        expect(screen.getByRole('group', { name: /Etiqueta single_choice/ })).toBeInTheDocument();
        expect(screen.getByRole('group', { name: /Etiqueta multiple_choice/ })).toBeInTheDocument();
        expect(screen.getByLabelText(/Etiqueta checkbox/)).toHaveAttribute('type', 'checkbox');
        expect(screen.getByLabelText(/Etiqueta date/)).toHaveAttribute('type', 'date');
        expect(screen.getByLabelText(/Etiqueta country/).tagName).toBe('SELECT');
        expect(screen.getByLabelText(/Etiqueta file/)).toHaveAttribute('type', 'file');
    });

    it('CA-005-15: single choice uses radios and multiple choice uses checkboxes, each with its own label', () => {
        renderForm([ALL_TYPES[3] as FormField, ALL_TYPES[4] as FormField]);

        const single = within(screen.getByRole('group', { name: /Etiqueta single_choice/ }));
        const multiple = within(screen.getByRole('group', { name: /Etiqueta multiple_choice/ }));

        expect(single.getAllByRole('radio')).toHaveLength(2);
        expect(single.getByLabelText('Ciencia')).toHaveAttribute('value', 'o_1');
        expect(multiple.getAllByRole('checkbox')).toHaveLength(2);
        expect(multiple.getByLabelText('Arte')).toHaveAttribute('name', 'f_multiple_choice[]');
    });

    it('CA-005-12: shows labels, help and options in the asked language and falls back to the base language', () => {
        const fields = [
            field('short_text', { help: { es: 'Ayuda base', en: 'English help' } }),
            field('single_choice', { position: 2, config: { options: OPTIONS } }),
            field('number', { position: 3, label: { es: 'Solo base' } }),
        ];

        renderForm(fields, { locale: 'en' });

        expect(screen.getByLabelText('Label short_text')).toBeInTheDocument();
        expect(screen.getByText('English help')).toBeInTheDocument();
        expect(screen.getByLabelText('Science')).toBeInTheDocument();
        // Sin traducción: cae al idioma base.
        expect(screen.getByLabelText('Arte')).toBeInTheDocument();
        expect(screen.getByLabelText('Solo base')).toBeInTheDocument();
    });

    it('CA-005-12: country names follow the language of the preview', () => {
        renderForm([field('country')], { locale: 'en' });

        expect(screen.getByRole('option', { name: 'Mexico' })).toHaveValue('MX');
    });

    it('CA-005-15: marks required fields in the text and with the required attribute', () => {
        renderForm([field('short_text', { required: true })]);

        expect(screen.getByLabelText(/Etiqueta short_text/)).toBeRequired();
        expect(screen.getByText(/obligatorio/)).toBeInTheDocument();
    });

    it('CA-005-15: ties the error to its field with aria-describedby, aria-invalid and an alert', () => {
        renderForm([field('short_text', { help: { es: 'Ayuda' } })], { errors: { f_short_text: 'Es demasiado largo' } });

        const input = screen.getByLabelText(/Etiqueta short_text/);
        const alert = screen.getByRole('alert');

        expect(alert).toHaveTextContent('Es demasiado largo');
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(input.getAttribute('aria-describedby')).toContain(alert.id);
        expect(input.getAttribute('aria-describedby')).toContain(screen.getByText('Ayuda').id);
    });

    it('does not show retired fields and respects the order of position', () => {
        const fields = [
            field('long_text', { position: 2 }),
            field('short_text', { position: 1 }),
            field('number', { position: 3, retiredAt: '2027-01-01T00:00:00Z' }),
        ];

        renderForm(fields);

        const labels = screen.getAllByText(/^Etiqueta /).map((node) => node.textContent);

        expect(labels).toEqual(['Etiqueta short_text', 'Etiqueta long_text']);
    });

    it('shows a notice when the form has no active fields', () => {
        renderForm([]);

        expect(screen.getByText('Sin campos')).toBeInTheDocument();
    });

    it('CA-005-13: in preview mode the submit control is disabled and nothing is sent', () => {
        const onSubmit = vi.fn();

        renderForm([field('short_text')], { onSubmit });

        const button = screen.getByRole('button', { name: 'Enviar propuesta' });
        fireEvent.submit(button.closest('form') as HTMLFormElement);

        expect(button).toBeDisabled();
        expect(screen.getByRole('note')).toHaveTextContent('Vista previa');
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('in public mode the submit control is enabled and hands the answers to onSubmit', () => {
        const onSubmit = vi.fn();

        renderForm([field('short_text')], { mode: 'public', onSubmit });
        fireEvent.change(screen.getByLabelText(/Etiqueta short_text/), { target: { value: 'Mi título' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enviar propuesta' }));

        expect(onSubmit).toHaveBeenCalledTimes(1);
        expect((onSubmit.mock.calls[0]?.[0] as FormData).get('f_short_text')).toBe('Mi título');
        expect(screen.queryByRole('note')).not.toBeInTheDocument();
    });

    it('CA-005-14: preview and public modes produce the same HTML except the submit control', () => {
        const withoutSubmit = (container: HTMLElement): string => {
            const clone = container.cloneNode(true) as HTMLElement;
            clone.querySelector('[data-form-submit]')?.remove();
            return clone.innerHTML;
        };

        const preview = renderForm(ALL_TYPES, { locale: 'en', mode: 'preview' });
        const previewHtml = withoutSubmit(preview.container);
        preview.unmount();

        const publicMode = renderForm(ALL_TYPES, { locale: 'en', mode: 'public' });
        const publicHtml = withoutSubmit(publicMode.container);

        expect(previewHtml).not.toBe('');
        expect(publicHtml).toBe(previewHtml);
    });
});
