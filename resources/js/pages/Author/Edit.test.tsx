import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import Edit from '@/pages/Author/Edit';
import type { AuthorEditData } from '@/types/author';

const patch = vi.fn();
const visit = vi.fn();
let pageErrors: Record<string, string> = {};

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    submit: {
        section_details: 'Datos de la propuesta',
        section_participants: 'Participantes',
        section_questions: 'Información adicional',
        field_title: 'Título de la propuesta',
        field_track: 'Eje temático',
        participant_heading: 'Participante :number',
        participant_primary: '(principal)',
        participant_first_name: 'Nombre',
        participant_last_name: 'Apellido',
        participant_email: 'Correo electrónico',
        participant_country: 'País',
        participant_job_title: 'Cargo',
        participant_company: 'Empresa',
        participant_bio: 'Semblanza profesional',
        participant_phone: 'Teléfono con código de país',
        participants_extra_question: '¿La propuesta incluye a otras personas participantes?',
        participants_extra_0: 'No, la participación es individual.',
        participants_extra_1: 'Sí, incluye a una persona adicional.',
        participants_extra_2: 'Sí, incluye a dos personas adicionales.',
    },
    author: {
        show: { reference: 'Referencia :reference' },
        edit: {
            title: 'Editar mi propuesta',
            intro: 'Puedes corregir tu propuesta.',
            files_note: 'Los archivos no se cambian aquí.',
            email_locked: 'El correo no se puede cambiar.',
            save: 'Guardar cambios',
            saving: 'Guardando…',
            cancel: 'Cancelar',
            reload: 'Recargar la propuesta',
            error_title: 'No se pudo guardar',
            error_fields: 'Corrige los campos marcados.',
        },
    },
};

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
    usePage: () => ({ props: { translations, locale: 'es', errors: pageErrors } }),
    router: {
        patch: (...args: unknown[]) => patch(...args),
        visit: (...args: unknown[]) => visit(...args),
    },
}));

const edit: AuthorEditData = {
    version: 4,
    formVersion: 7,
    participantFields: {},
    extraSectionTitle: null,
    fields: [
        {
            key: 'summary',
            type: 'long_text',
            required: true,
            position: 1,
            identifiesAuthor: false,
            label: { es: 'Resumen' },
            help: [],
            config: [],
            retiredAt: null,
        },
        {
            key: 'ok',
            type: 'checkbox',
            required: false,
            position: 2,
            identifiesAuthor: false,
            label: { es: 'Acepto' },
            help: [],
            config: [],
            retiredAt: null,
        },
        {
            key: 'level',
            type: 'single_choice',
            required: false,
            position: 3,
            identifiesAuthor: false,
            label: { es: 'Nivel' },
            help: [],
            config: {
                options: [
                    { value: 'a', label: { es: 'Básico' } },
                    { value: 'b', label: { es: 'Avanzado' } },
                ],
            },
            retiredAt: null,
        },
    ],
    defaultLocale: 'es',
    translations: {
        forms: {
            renderer: {
                form_label: 'Formulario',
                submit: 'Enviar',
                required: 'obligatorio',
                select_country: 'Elige un país',
                empty: 'Vacío',
                preview_notice: '',
            },
        },
    },
    tracks: [
        { id: 't1', name: 'Gestión' },
        { id: 't2', name: 'Ecología' },
    ],
    initialValues: {
        's.title': 'Parques vivos',
        's.track_id': 't2',
        's.participants.0.first_name': 'Ana',
        's.participants.0.last_name': 'Pérez',
        's.participants.0.country': 'MX',
        summary: 'Un resumen',
        ok: true,
        level: 'b',
    },
};

const props = {
    event: { name: 'Congreso Parques' },
    organization: { name: 'Parques de México', slug: 'parques' },
    reference: 'ABCD1234',
    participants: [{ email: 'ana@example.test', isPrimary: true }],
    edit,
    updateUrl: '/parques/events/congreso/my-submission/edit',
    showUrl: '/parques/events/congreso/my-submission',
};

describe('Author/Edit page', () => {
    beforeEach(() => {
        patch.mockClear();
        visit.mockClear();
        pageErrors = {};
    });

    it('starts every control with the current value of the proposal', () => {
        render(<Edit {...props} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Editar mi propuesta' })).toBeInTheDocument();
        expect(screen.getByLabelText(/Título de la propuesta/)).toHaveValue('Parques vivos');
        expect(screen.getByLabelText(/Resumen/)).toHaveValue('Un resumen');
        expect(screen.getByLabelText(/Acepto/)).toBeChecked();
        expect(screen.getByLabelText('Avanzado')).toBeChecked();
        expect(screen.getByLabelText('Ecología')).toBeChecked();
        expect(screen.getByLabelText(/Nombre/)).toHaveValue('Ana');
    });

    it('shows the participant email as text, never as an editable control', () => {
        render(<Edit {...props} />);

        expect(screen.getByText(/ana@example.test/)).toBeInTheDocument();
        expect(screen.queryByLabelText('Correo electrónico')).toBeNull();
    });

    it('sends the version the screen was opened with and the edited values', () => {
        render(<Edit {...props} />);

        fireEvent.change(screen.getByLabelText(/Título de la propuesta/), { target: { value: 'Parques muy vivos' } });
        fireEvent.submit(screen.getByRole('button', { name: 'Guardar cambios' }).closest('form') as HTMLFormElement);

        expect(patch).toHaveBeenCalledTimes(1);
        const [url, payload] = patch.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/parques/events/congreso/my-submission/edit');
        expect(payload).toMatchObject({
            version: 4,
            form_version: 7,
            title: 'Parques muy vivos',
            track_id: 't2',
            answers: { summary: 'Un resumen', ok: true, level: 'b' },
        });
    });

    it('shows a domain error with a reload button and keeps the form', () => {
        pageErrors = { edit: 'La propuesta cambió mientras la editabas.' };
        render(<Edit {...props} />);

        expect(screen.getByRole('alert')).toHaveTextContent('La propuesta cambió mientras la editabas.');
        fireEvent.click(screen.getByRole('button', { name: 'Recargar la propuesta' }));

        expect(visit).toHaveBeenCalledWith(
            '/parques/events/congreso/my-submission/edit',
            expect.objectContaining({ method: 'get', preserveState: false }),
        );
        expect(screen.getByLabelText(/Título de la propuesta/)).toBeInTheDocument();
    });

    it('associates a server field error with its control', () => {
        pageErrors = { title: 'El título es obligatorio.' };
        render(<Edit {...props} />);

        expect(screen.getByLabelText(/Título de la propuesta/)).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('El título es obligatorio.')).toBeInTheDocument();
    });

    it('links back to the proposal', () => {
        render(<Edit {...props} />);

        expect(screen.getByRole('link', { name: 'Cancelar' })).toHaveAttribute('href', '/parques/events/congreso/my-submission');
    });
});
