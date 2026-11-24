import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import { SubmissionForm } from '@/components/organisms/SubmissionForm';
import { createTranslate } from '@/lib/translate';
import type { FormField } from '@/types/form';
import type { PublicSubmissionForm, SubmissionPayload, SubmitFailure } from '@/types/submission';

vi.mock('@/components/infrastructure/TurnstileWidget', () => ({
    TurnstileWidget: ({ onToken }: { onToken: (token: string | null) => void }): ReactNode => (
        <button type="button" onClick={() => onToken('token-turnstile')}>
            resolver-turnstile
        </button>
    ),
}));

const t = createTranslate({
    forms: {
        renderer: {
            required: 'obligatorio',
            select_country: 'Selecciona un país',
            form_label: 'Formulario de propuesta',
            submit: 'Enviar propuesta',
        },
    },
    submit: {
        send_action: 'Enviar propuesta',
        sending: 'Enviando…',
        field_title: 'Título de la propuesta',
        field_track: 'Eje temático',
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
        participant_heading: 'Participante :number',
        participant_add: 'Agregar participante',
        participant_remove: 'Quitar participante :number',
        consent_prefix: 'He leído y acepto el ',
        consent_link: 'aviso de privacidad',
        consent_suffix: ' (versión :version).',
        third_parties: 'Declaro que cuento con su autorización.',
        error_title: 'No se pudo enviar la propuesta',
        error_fields: 'Corrige los campos marcados.',
        error_call_not_open: 'La convocatoria ya no recibe propuestas.',
        error_session: 'La sesión del formulario venció.',
        error_too_many: 'Demasiados intentos. Reintenta en :minutes min.',
        reload_form: 'Actualizar el formulario',
        turnstile_loading: 'Verificando…',
    },
});

const summary: FormField = {
    key: 'summary',
    type: 'long_text',
    required: true,
    position: 1,
    identifiesAuthor: false,
    label: { es: 'Resumen' },
    help: [],
    config: [],
    retiredAt: null,
};

const form: PublicSubmissionForm = {
    version: 3,
    participantFields: {},
    participantsPosition: 'top',
    title: null,
    extraSectionTitle: null,
    fields: [summary],
    defaultLocale: 'es',
    translations: {},
    idempotencyKey: 'clave-de-idempotencia-1',
    uploadToken: 'token-de-carga',
    uploadUrl: '/parques/events/congreso/call/abc/files',
    turnstileSiteKey: 'site-publica',
    minParticipants: 1,
    maxParticipants: 3,
    tracks: [
        { id: 'track-1', name: 'Ciencia' },
        { id: 'track-2', name: 'Arte' },
    ],
    privacyNotice: { id: 'notice-1', version: 2, url: '/parques/events/congreso/privacy' },
};

interface Overrides {
    errors?: Record<string, string>;
    failure?: SubmitFailure | null;
    onSubmit?: (payload: SubmissionPayload) => void;
    onRefresh?: () => void;
    form?: PublicSubmissionForm;
}

function ui(overrides: Overrides = {}) {
    return (
        <SubmissionForm
            form={overrides.form ?? form}
            locale="es"
            t={t}
            errors={overrides.errors ?? {}}
            failure={overrides.failure ?? null}
            isProcessing={false}
            isRefreshing={false}
            onSubmit={overrides.onSubmit ?? vi.fn()}
            onRefresh={overrides.onRefresh ?? vi.fn()}
        />
    );
}

function fill(label: RegExp | string, value: string) {
    fireEvent.change(screen.getByLabelText(label), { target: { value } });
}

function fillAll() {
    fill(/Título de la propuesta/, 'Parques vivos');
    fireEvent.click(screen.getByLabelText('Arte'));
    fill(/^Nombre/, 'Ana');
    fill(/Apellido/, 'Pérez');
    fill(/Correo electrónico/, 'ANA@example.test');
    fill(/^País/, 'MX');
    fill(/Resumen/, 'Un resumen');
    fireEvent.click(screen.getByLabelText(/He leído y acepto/));
    fireEvent.click(screen.getByRole('button', { name: 'resolver-turnstile' }));
}

describe('SubmissionForm', () => {
    it('CA-006-55: renders the fixed block, the published fields, the consent with the notice link and the anti-robot check', () => {
        render(ui());

        expect(screen.getByLabelText(/Título de la propuesta/)).toBeInTheDocument();
        expect(screen.getByRole('group', { name: /Eje temático/ })).toBeInTheDocument();
        expect(screen.getByLabelText(/Resumen/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /aviso de privacidad/ })).toHaveAttribute('href', '/parques/events/congreso/privacy');
        expect(screen.getByRole('button', { name: 'resolver-turnstile' })).toBeInTheDocument();
        // Un solo participante: no se pide la declaración de terceros.
        expect(screen.queryByLabelText(/autorización/)).not.toBeInTheDocument();
    });

    it('CA-006-5: the question about other people adds and removes participants within the limits of the call and asks for the third-party declaration', () => {
        render(ui());

        expect(screen.getAllByRole('group', { name: /Participante \d/ })).toHaveLength(1);
        expect(screen.getByRole('radio', { name: 'No, la participación es individual.' })).toBeChecked();

        fireEvent.click(screen.getByRole('radio', { name: 'Sí, incluye a dos personas adicionales.' }));

        expect(screen.getAllByRole('group', { name: /Participante \d/ })).toHaveLength(3);
        expect(screen.getByLabelText(/autorización/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: 'Sí, incluye a una persona adicional.' }));

        expect(screen.getAllByRole('group', { name: /Participante \d/ })).toHaveLength(2);

        fireEvent.click(screen.getByRole('radio', { name: 'No, la participación es individual.' }));

        expect(screen.getAllByRole('group', { name: /Participante \d/ })).toHaveLength(1);
        expect(screen.queryByLabelText(/autorización/)).not.toBeInTheDocument();
    });

    it('uses the own title of the questions section and puts the participants after the questions when the form says so', () => {
        render(ui({ form: { ...form, extraSectionTitle: 'Datos del proyecto', participantsPosition: 'bottom' } }));

        expect(screen.getByRole('heading', { name: 'Datos del proyecto' })).toBeInTheDocument();

        const question = screen.getByLabelText(/Resumen/);
        const participants = screen.getByRole('group', { name: /Participante \d/ });

        // El resumen (pregunta del formulario) va antes que el bloque de participantes.
        expect(question.compareDocumentPosition(participants) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('sends the payload with the issued credentials, the form version, the consent and the Turnstile token', async () => {
        const onSubmit = vi.fn();
        render(ui({ onSubmit }));
        fillAll();

        fireEvent.click(screen.getByRole('button', { name: 'Enviar propuesta' }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        expect(onSubmit).toHaveBeenCalledWith({
            idempotency_key: 'clave-de-idempotencia-1',
            upload_token: 'token-de-carga',
            form_version: 3,
            locale: 'es',
            title: 'Parques vivos',
            track_id: 'track-2',
            answers: { summary: 'Un resumen' },
            participants: [{ first_name: 'Ana', last_name: 'Pérez', email: 'ANA@example.test', country: 'MX', is_primary: true }],
            consent: true,
            privacy_notice_version_id: 'notice-1',
            third_parties_authorized: false,
            turnstile_token: 'token-turnstile',
        });
    });

    it('does not send before the anti-robot check finishes', async () => {
        const onSubmit = vi.fn();
        render(ui({ onSubmit }));
        fill(/Título de la propuesta/, 'Parques vivos');

        fireEvent.click(screen.getByRole('button', { name: 'Enviar propuesta' }));

        expect(await screen.findByText('Verificando…')).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('CA-006-56: keeps what was typed, ties each error to its field and moves focus to the first error on a 422', () => {
        const view = render(ui());
        fillAll();

        view.rerender(
            ui({
                errors: {
                    'participants.0.email': 'Correo no válido.',
                    'answers.summary': 'El resumen es obligatorio.',
                    consent: 'Debes aceptar el aviso.',
                },
            }),
        );

        // Lo escrito sigue ahí.
        expect(screen.getByLabelText(/Título de la propuesta/)).toHaveValue('Parques vivos');
        expect(screen.getByLabelText(/Resumen/)).toHaveValue('Un resumen');
        expect(screen.getByLabelText(/Correo electrónico/)).toHaveValue('ANA@example.test');

        // Cada error está asociado a su campo.
        const email = screen.getByLabelText(/Correo electrónico/);
        expect(email).toHaveAttribute('aria-invalid', 'true');
        expect(document.getElementById(email.getAttribute('aria-describedby') ?? '')).toHaveTextContent('Correo no válido.');
        const summaryField = screen.getByLabelText(/Resumen/);
        expect(summaryField).toHaveAttribute('aria-invalid', 'true');
        expect(document.getElementById(summaryField.getAttribute('aria-describedby') ?? '')).toHaveTextContent(
            'El resumen es obligatorio.',
        );
        expect(screen.getByLabelText(/He leído y acepto/)).toHaveAttribute('aria-invalid', 'true');

        // El foco va al primer error en el orden del formulario.
        expect(email).toHaveFocus();
    });

    it('CA-006-56: keeps what was typed and focuses the alert when the call is no longer open, and blocks the submit', () => {
        const view = render(ui());
        fillAll();

        view.rerender(ui({ failure: { code: 'CALL_NOT_OPEN', message: null, retryAfterSeconds: null } }));

        expect(screen.getByLabelText(/Título de la propuesta/)).toHaveValue('Parques vivos');
        expect(screen.getByText('La convocatoria ya no recibe propuestas.')).toBeInTheDocument();
        expect(screen.getByText('La convocatoria ya no recibe propuestas.').closest('[role="alert"]')).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Enviar propuesta' })).toBeDisabled();
    });

    it('CA-006-56: keeps what was typed on FORM_OUTDATED and offers to refresh the form', () => {
        const onRefresh = vi.fn();
        const view = render(ui({ onRefresh }));
        fillAll();

        view.rerender(ui({ onRefresh, failure: { code: 'FORM_OUTDATED', message: null, retryAfterSeconds: null } }));

        expect(screen.getByLabelText(/Resumen/)).toHaveValue('Un resumen');
        fireEvent.click(screen.getByRole('button', { name: 'Actualizar el formulario' }));
        expect(onRefresh).toHaveBeenCalledTimes(1);
    });

    it('CA-006-20: tells the visitor how long to wait after a 429 without losing what was typed', () => {
        const view = render(ui());
        fillAll();

        view.rerender(ui({ failure: { code: 'TOO_MANY_REQUESTS', message: null, retryAfterSeconds: 540 } }));

        expect(screen.getByText('Demasiados intentos. Reintenta en 9 min.')).toBeInTheDocument();
        expect(screen.getByLabelText(/Resumen/)).toHaveValue('Un resumen');
    });

    it('CA-006-52: asks for the consent again when the privacy notice version changed', () => {
        const view = render(ui());
        fillAll();
        expect(screen.getByLabelText(/He leído y acepto/)).toBeChecked();

        view.rerender(ui({ form: { ...form, privacyNotice: { id: 'notice-2', version: 3, url: form.privacyNotice.url } } }));

        expect(screen.getByLabelText(/He leído y acepto/)).not.toBeChecked();
        expect(within(screen.getByLabelText(/He leído y acepto/).closest('div') as HTMLElement).getByRole('link')).toBeInTheDocument();
    });
});
