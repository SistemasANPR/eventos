import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';

import Submit from '@/pages/Calls/Submit';
import type { PublicCall } from '@/types/call';
import type { PublicSubmissionForm } from '@/types/submission';

const post = vi.fn();
const reload = vi.fn();
let pageErrors: Record<string, string> = {};

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    call: {
        state: { scheduled: 'Próximamente', open: 'Abierta', paused: 'En pausa', closed: 'Cerrada' },
        state_hint: {
            scheduled: 'La convocatoria aún no abre.',
            open: 'La convocatoria recibe propuestas.',
            paused: 'La recepción de propuestas está en pausa por el momento.',
            closed: 'La convocatoria ya no recibe propuestas.',
        },
    },
    submit: {
        title: 'Enviar propuesta',
        send_action: 'Enviar propuesta',
        back_to_call: 'Volver a la convocatoria',
        not_ready_title: 'El formulario aún no está listo',
        not_ready_hint: 'Vuelve más tarde.',
        field_title: 'Título de la propuesta',
        error_title: 'No se pudo enviar la propuesta',
        error_call_not_open: 'La convocatoria ya no recibe propuestas.',
        participant_heading: 'Participante :number',
    },
};

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
    router: {
        post: (...args: unknown[]) => post(...args),
        reload: (...args: unknown[]) => reload(...args),
    },
    usePage: () => ({ props: { translations, locale: 'es', errors: pageErrors } }),
}));

vi.mock('@/components/infrastructure/TurnstileWidget', () => ({
    TurnstileWidget: ({ onToken }: { onToken: (token: string | null) => void }): ReactNode => (
        <button type="button" onClick={() => onToken('tok')}>
            resolver-turnstile
        </button>
    ),
}));

const call: PublicCall = {
    title: 'Convocatoria de ponencias',
    description: null,
    submissionType: 'Sesión',
    state: 'open',
    opensAt: '2027-01-10T15:00:00Z',
    closesAt: '2027-02-15T23:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '12h',
    locale: 'es',
    locales: ['es'],
    tracks: [],
};

const form: PublicSubmissionForm = {
    version: 1,
    fields: [],
    defaultLocale: 'es',
    translations: { forms: { renderer: { submit: 'Enviar propuesta', form_label: 'Formulario', required: 'obligatorio', select_country: 'País' } } },
    idempotencyKey: 'clave-de-idempotencia-1',
    uploadToken: 'token',
    uploadUrl: '/files',
    turnstileSiteKey: 'site',
    minParticipants: 1,
    maxParticipants: 1,
    tracks: [{ id: 't1', name: 'Ciencia' }],
    privacyNotice: { id: 'n1', version: 1, url: '/privacy' },
};

function renderPage(overrides: { call?: Partial<PublicCall>; form?: PublicSubmissionForm | null } = {}) {
    return render(
        <Submit
            call={{ ...call, ...overrides.call }}
            event={{ name: 'Congreso' }}
            organization={{ name: 'Parques', slug: 'parques' }}
            callUrl="/parques/events/congreso/call/ponencias"
            submitUrl="/parques/events/congreso/call/ponencias/submit"
            form={overrides.form === undefined ? form : overrides.form}
        />,
    );
}

beforeEach(() => {
    post.mockReset();
    reload.mockReset();
    pageErrors = {};
});

describe('Calls/Submit page', () => {
    it('CA-006-55: shows the state and no form when the call is not open', () => {
        renderPage({ call: { state: 'closed' }, form: null });

        expect(screen.getByTestId('submit-unavailable')).toHaveTextContent('Cerrada');
        expect(screen.getByText('La convocatoria ya no recibe propuestas.')).toBeInTheDocument();
        expect(screen.queryByRole('form')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Volver a la convocatoria' })).toHaveAttribute('href', '/parques/events/congreso/call/ponencias');
    });

    it('CA-006-55: shows the not-ready notice when the call is open but has no form or notice', () => {
        renderPage({ form: null });

        expect(screen.getByText('El formulario aún no está listo')).toBeInTheDocument();
        expect(screen.getByText('Vuelve más tarde.')).toBeInTheDocument();
    });

    it('CA-006-56: posts the payload with Inertia and keeps what was typed when the server answers CALL_NOT_OPEN', async () => {
        renderPage();
        fireEvent.change(screen.getByLabelText(/Título de la propuesta/), { target: { value: 'Parques vivos' } });
        fireEvent.click(screen.getByRole('button', { name: 'resolver-turnstile' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enviar propuesta' }));

        await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
        const [url, payload, options] = post.mock.calls[0] as [string, { title: string; turnstile_token: string }, { preserveState: boolean; onHttpException: (response: unknown) => boolean }];
        expect(url).toBe('/parques/events/congreso/call/ponencias/submit');
        expect(payload.title).toBe('Parques vivos');
        expect(payload.turnstile_token).toBe('tok');
        expect(options.preserveState).toBe(true);

        // Respuesta 409 del servidor: la página no navega y no pierde lo escrito.
        let handled: boolean | undefined;
        act(() => {
            handled = options.onHttpException({ status: 409, headers: {}, data: { code: 'CALL_NOT_OPEN', message: 'cerrada' } });
        });

        expect(handled).toBe(false);
        expect(await screen.findByText('La convocatoria ya no recibe propuestas.')).toBeInTheDocument();
        expect(screen.getByLabelText(/Título de la propuesta/)).toHaveValue('Parques vivos');
    });

    it('refreshes only the form (not the whole page) when the visitor asks to update it', async () => {
        renderPage();
        fireEvent.click(screen.getByRole('button', { name: 'resolver-turnstile' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enviar propuesta' }));
        await waitFor(() => expect(post).toHaveBeenCalled());
        const options = post.mock.calls[0]?.[2] as { onHttpException: (response: unknown) => boolean };
        act(() => {
            options.onHttpException({ status: 409, headers: {}, data: { code: 'FORM_OUTDATED' } });
        });

        fireEvent.click(await screen.findByRole('button', { name: 'submit.reload_form' }));

        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['form'] }));
    });
});
