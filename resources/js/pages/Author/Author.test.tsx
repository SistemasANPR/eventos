import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import AccessInvalid from '@/pages/Author/AccessInvalid';
import ConfirmAccess from '@/pages/Author/ConfirmAccess';
import RequestLink from '@/pages/Author/RequestLink';
import Show from '@/pages/Author/Show';
import { createTranslate } from '@/lib/translate';
import type { AccessLinkPageProps, AuthorSubmission } from '@/types/author';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language' },
    languages: { es: 'Español', en: 'English' },
    author: {
        request: {
            title: 'Pedir mi enlace de acceso',
            intro: 'Escribe tu correo. El enlace dura :minutes minutos y solo se usa una vez.',
            email_label: 'Correo electrónico',
            submit: 'Enviarme el enlace',
            sending: 'Enviando…',
            sent_title: 'Revisa tu correo',
            sent_body: 'Hasta :max enlaces, caducan en :minutes minutos.',
            too_many_title: 'Demasiadas solicitudes',
            too_many_body: 'Espera un momento.',
            turnstile_failed: 'No se pudo cargar la verificación.',
            turnstile_missing: 'La verificación no está disponible.',
            back_to_event: 'Volver al evento',
        },
        confirm: {
            title: 'Abrir mi propuesta',
            body: 'Confirma para abrir «:event» (:minutes min).',
            action: 'Abrir mi propuesta',
            opening: 'Abriendo…',
        },
        invalid: { title: 'El enlace no es válido', body: 'Pide uno nuevo.', request_link: 'Pedir un enlace nuevo' },
        show: {
            title: 'Mi propuesta',
            reference: 'Referencia :reference',
            status_label: 'Estado',
            status: { submitted: 'Enviada', withdrawn: 'Retirada' },
            withdrawn_notice: 'Retiraste esta propuesta el :date.',
            track: 'Eje temático',
            submitted_on: 'Fecha de envío',
            answers: 'Respuestas',
            no_answers: 'Sin respuestas adicionales.',
            yes: 'Sí',
            no: 'No',
            files_count: ':count archivo(s)',
            no_value: 'Sin respuesta',
            participants: 'Participantes',
            primary: 'Principal',
            session_notice: 'Tu acceso dura :minutes minutos.',
            back_to_event: 'Volver al evento',
            edit_action: 'Editar mi propuesta',
            edit_until: 'Puedes editar hasta el :date.',
            edit_closed: 'Cerró el :date.',
            edit_closed_no_date: 'La convocatoria cerró.',
            edit_in_process: 'Ya está en proceso.',
            logout: 'Cerrar sesión',
            logging_out: 'Cerrando…',
            updated_notice: 'Guardamos los cambios.',
            withdraw: {
                action: 'Retirar mi propuesta',
                confirm_title: '¿Retirar tu propuesta?',
                confirm_body: 'Dejará de participar.',
                confirm_action: 'Sí, retirar',
                cancel: 'Cancelar',
                withdrawing: 'Retirando…',
            },
        },
    },
};

const post = vi.fn();
const routerPost = vi.fn();
let pageErrors: Record<string, string> = {};
const transform = vi.fn();

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
    usePage: () => ({ url: '/parques/events/congreso/my-submission/request-link', props: { errors: pageErrors } }),
    router: { post: (...args: unknown[]) => routerPost(...args) },
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        transform: (callback: (data: Record<string, string>) => Record<string, string>) => {
            transform(callback(initial));
        },
        post,
    }),
}));

vi.mock('@/components/infrastructure/TurnstileWidget', () => ({
    TurnstileWidget: () => <div data-testid="turnstile-widget" />,
}));

const requestProps: AccessLinkPageProps = {
    event: { name: 'Congreso Parques', locale: 'es', locales: ['es', 'en'] },
    organization: { name: 'Parques de México', slug: 'parques' },
    turnstileSiteKey: 'sitekey',
    linkRequested: false,
    tooManyRequests: false,
    maxLinks: 5,
    expiresInMinutes: 30,
    eventUrl: '/parques/events/congreso?lang=es',
};

const submission: AuthorSubmission = {
    reference: 'ABCD1234',
    title: 'Parques <b>vivos</b>',
    status: 'submitted',
    locale: 'es',
    version: 1,
    submittedAt: '2027-01-20T12:00:00Z',
    withdrawnAt: null,
    track: 'Gestión',
    answers: [
        { key: 'summary', label: 'Resumen', type: 'long_text', value: 'Un resumen <script>x</script>' },
        { key: 'ok', label: 'Acepta', type: 'checkbox', value: true },
        { key: 'tags', label: 'Temas', type: 'multiple_choice', value: ['Agua', 'Suelo'] },
        { key: 'slides', label: 'Láminas', type: 'file', value: '2' },
        { key: 'empty', label: 'Opcional', type: 'short_text', value: null },
    ],
    participants: [
        { fullName: 'Ana Pérez', email: 'ana@example.test', country: 'MX', jobTitle: 'Dra.', company: null, bio: null, isPrimary: true },
        {
            fullName: 'Luis Gómez',
            email: 'luis@example.test',
            country: 'CO',
            jobTitle: null,
            company: null,
            bio: 'Biografía',
            isPrimary: false,
        },
    ],
};

describe('Author/RequestLink page', () => {
    beforeEach(() => {
        post.mockClear();
        transform.mockClear();
    });

    it('CA-034-13: explains before sending that the link arrives by email, lasts its minutes and is single use', () => {
        render(<RequestLink {...requestProps} />);

        expect(screen.getByText('Escribe tu correo. El enlace dura 30 minutos y solo se usa una vez.')).toBeInTheDocument();
    });

    it('shows a labelled email field, the anti-robot check and the send button', () => {
        render(<RequestLink {...requestProps} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Pedir mi enlace de acceso' })).toBeInTheDocument();
        expect(screen.getByLabelText('Correo electrónico')).toHaveAttribute('type', 'email');
        expect(screen.getByTestId('turnstile-widget')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enviarme el enlace' })).toBeInTheDocument();
    });

    it('CA-032-6: links back to the event only when the event still has a public page', () => {
        const { rerender } = render(<RequestLink {...requestProps} />);
        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');

        rerender(<RequestLink {...requestProps} eventUrl={null} />);
        expect(screen.queryByRole('link', { name: 'Volver al evento' })).toBeNull();
    });

    it('shows the same neutral notice after a request, without saying whether the email exists', () => {
        render(<RequestLink {...requestProps} linkRequested />);

        expect(screen.getByRole('status')).toHaveTextContent('Revisa tu correo');
        expect(screen.getByRole('status')).toHaveTextContent('Hasta 5 enlaces, caducan en 30 minutos.');
    });

    it('announces the rate limit as an alert', () => {
        render(<RequestLink {...requestProps} tooManyRequests />);

        expect(screen.getByRole('alert')).toHaveTextContent('Demasiadas solicitudes');
    });

    it('tells the person when the security check is not available instead of leaving a silent form', () => {
        render(<RequestLink {...requestProps} turnstileSiteKey="" />);

        expect(screen.getByRole('alert')).toHaveTextContent('La verificación no está disponible.');
        expect(screen.queryByTestId('turnstile-widget')).toBeNull();
    });

    it('posts the form to the current page', () => {
        render(<RequestLink {...requestProps} />);

        fireEvent.submit(screen.getByRole('button', { name: 'Enviarme el enlace' }).closest('form') as HTMLFormElement);

        expect(post).toHaveBeenCalledWith('/parques/events/congreso/my-submission/request-link', expect.anything());
    });
});

describe('Author/ConfirmAccess page', () => {
    beforeEach(() => post.mockClear());

    it('asks to confirm and only posts when the button is pressed', () => {
        render(
            <ConfirmAccess
                event={{ name: 'Congreso Parques' }}
                organization={requestProps.organization}
                consumeUrl="/parques/events/congreso/my-submission/access/abc"
                expiresInMinutes={60}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Abrir mi propuesta' })).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Abrir mi propuesta' }));

        expect(post).toHaveBeenCalledWith('/parques/events/congreso/my-submission/access/abc');
    });
});

describe('Author/AccessInvalid page', () => {
    it('shows one generic message and a link to request a new one', () => {
        render(
            <AccessInvalid
                event={{ name: 'Congreso Parques' }}
                organization={requestProps.organization}
                requestLinkUrl="/parques/events/congreso/my-submission/request-link"
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Pide uno nuevo.');
        expect(screen.getByRole('link', { name: 'Pedir un enlace nuevo' })).toHaveAttribute(
            'href',
            '/parques/events/congreso/my-submission/request-link',
        );
    });
});

describe('Author/Show page', () => {
    const withdrawUrl = '/parques/events/congreso/my-submission/withdraw';
    const props = {
        event: { name: 'Congreso Parques' },
        organization: requestProps.organization,
        submission,
        sessionMinutes: 60,
        eventUrl: '/parques/events/congreso?lang=es',
        withdrawUrl,
        editUrl: '/parques/events/congreso/my-submission/edit',
        editPolicy: { state: 'editable' as const, until: '2027-02-15T23:00:00Z' },
        timezone: 'America/Mexico_City',
        timeFormat: '24h' as const,
        logoutUrl: '/parques/events/congreso/my-submission/logout',
        wasUpdated: false,
    };

    beforeEach(() => {
        routerPost.mockClear();
        pageErrors = {};
    });

    it('CA-034-14: says until when it can be edited, in the event timezone and time format', () => {
        render(<Show {...props} />);

        expect(screen.getByTestId('edit-notice')).toHaveTextContent(/Puedes editar hasta el .*17:00/);
    });

    it('CA-034-14: tells why it cannot be edited: call closed (with or without date) or already in process', () => {
        const closed = render(<Show {...props} editUrl={null} editPolicy={{ state: 'call_closed', until: '2027-02-15T23:00:00Z' }} />);

        expect(screen.getByTestId('edit-notice')).toHaveTextContent(/Cerró el .*17:00/);
        expect(screen.queryByRole('link', { name: 'Editar mi propuesta' })).not.toBeInTheDocument();
        closed.unmount();

        const undated = render(<Show {...props} editUrl={null} editPolicy={{ state: 'call_closed', until: null }} />);

        expect(screen.getByTestId('edit-notice')).toHaveTextContent('La convocatoria cerró.');
        undated.unmount();

        render(<Show {...props} editUrl={null} editPolicy={{ state: 'in_process', until: null }} />);

        expect(screen.getByTestId('edit-notice')).toHaveTextContent('Ya está en proceso.');
    });

    it('CA-034-14: a withdrawn proposal shows its own notice and no edit notice', () => {
        render(
            <Show
                {...props}
                submission={{ ...submission, status: 'withdrawn', withdrawnAt: '2027-01-25T12:00:00Z' }}
                editUrl={null}
                editPolicy={{ state: 'in_process', until: null }}
            />,
        );

        expect(screen.queryByTestId('edit-notice')).not.toBeInTheDocument();
        expect(screen.getByText(/Retiraste esta propuesta/)).toBeInTheDocument();
    });

    it('CA-034-16: the sign-out button posts to the logout route', () => {
        render(<Show {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }));

        expect(routerPost).toHaveBeenCalledWith('/parques/events/congreso/my-submission/logout', {}, expect.anything());
    });

    it('shows title, status, answers and participants', () => {
        render(<Show {...props} />);

        expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Parques <b>vivos</b>');
        expect(screen.getByTestId('submission-status')).toHaveTextContent('Enviada');
        expect(screen.getByText('Referencia ABCD1234')).toBeInTheDocument();
        expect(screen.getByText('Un resumen <script>x</script>')).toBeInTheDocument();
        expect(screen.getByText('Sí')).toBeInTheDocument();
        expect(screen.getByText('Agua, Suelo')).toBeInTheDocument();
        expect(screen.getByText('2 archivo(s)')).toBeInTheDocument();
        expect(screen.getByText('Sin respuesta')).toBeInTheDocument();
        expect(screen.getByText('Ana Pérez')).toBeInTheDocument();
        expect(screen.getByText('Principal')).toBeInTheDocument();
        expect(screen.getByText('Biografía')).toBeInTheDocument();
        expect(screen.getByText('Tu acceso dura 60 minutos.')).toBeInTheDocument();
    });

    it('renders user text as text, never as markup', () => {
        const { container } = render(<Show {...props} />);

        expect(container.querySelector('main script')).toBeNull();
        expect(container.querySelector('main b')).toBeNull();
    });

    it('CA-032-6: links back to the event only when the event still has a public page', () => {
        const { rerender } = render(<Show {...props} />);
        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');

        rerender(<Show {...props} eventUrl={null} />);
        expect(screen.queryByRole('link', { name: 'Volver al evento' })).toBeNull();
    });

    it('links to the edit page only when the server says the proposal can be edited', () => {
        const { rerender } = render(<Show {...props} />);
        expect(screen.getByRole('link', { name: 'Editar mi propuesta' })).toHaveAttribute(
            'href',
            '/parques/events/congreso/my-submission/edit',
        );

        rerender(<Show {...props} editUrl={null} />);
        expect(screen.queryByRole('link', { name: 'Editar mi propuesta' })).toBeNull();
    });

    it('confirms a saved edit and shows an edit error from the server', () => {
        pageErrors = { edit: 'La propuesta ya no se puede editar.' };
        render(<Show {...props} wasUpdated />);

        expect(screen.getByText('Guardamos los cambios.')).toBeInTheDocument();
        expect(screen.getByText('La propuesta ya no se puede editar.')).toBeInTheDocument();
    });

    it('announces a withdrawn proposal', () => {
        render(<Show {...props} submission={{ ...submission, status: 'withdrawn', withdrawnAt: '2027-01-21T10:00:00Z' }} />);

        expect(screen.getByTestId('submission-status')).toHaveTextContent('Retirada');
        expect(screen.getByRole('status')).toHaveTextContent('Retiraste esta propuesta el');
    });

    it('asks for confirmation before withdrawing and only then posts', () => {
        render(<Show {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Retirar mi propuesta' }));
        expect(routerPost).not.toHaveBeenCalled();
        expect(screen.getByText('¿Retirar tu propuesta?')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Sí, retirar' }));

        expect(routerPost).toHaveBeenCalledWith(withdrawUrl, {}, expect.anything());
    });

    it('lets the person back out of withdrawing', () => {
        render(<Show {...props} />);

        fireEvent.click(screen.getByRole('button', { name: 'Retirar mi propuesta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

        expect(screen.queryByText('¿Retirar tu propuesta?')).toBeNull();
        expect(screen.getByRole('button', { name: 'Retirar mi propuesta' })).toBeInTheDocument();
    });

    it('does not offer withdrawing when the server says it is not possible', () => {
        render(<Show {...props} withdrawUrl={null} />);

        expect(screen.queryByRole('button', { name: 'Retirar mi propuesta' })).toBeNull();
    });

    it('announces a server error about withdrawing', () => {
        pageErrors = { withdraw: 'Ya no se puede retirar.' };
        render(<Show {...props} />);

        expect(screen.getByRole('alert')).toHaveTextContent('Ya no se puede retirar.');
    });

    it('shows a message when there are no extra answers', () => {
        render(<Show {...props} submission={{ ...submission, answers: [] }} />);

        expect(screen.getByText('Sin respuestas adicionales.')).toBeInTheDocument();
    });
});
