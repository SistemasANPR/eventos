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
            intro: 'Escribe tu correo.',
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
        confirm: { title: 'Abrir mi propuesta', body: 'Confirma para abrir «:event» (:minutes min).', action: 'Abrir mi propuesta', opening: 'Abriendo…' },
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
        },
    },
};

const post = vi.fn();
const transform = vi.fn();

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
    usePage: () => ({ url: '/parques/events/congreso/my-submission/request-link' }),
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
        { fullName: 'Luis Gómez', email: 'luis@example.test', country: 'CO', jobTitle: null, company: null, bio: 'Biografía', isPrimary: false },
    ],
};

describe('Author/RequestLink page', () => {
    beforeEach(() => {
        post.mockClear();
        transform.mockClear();
    });

    it('shows a labelled email field, the anti-robot check and the send button', () => {
        render(<RequestLink {...requestProps} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Pedir mi enlace de acceso' })).toBeInTheDocument();
        expect(screen.getByLabelText('Correo electrónico')).toHaveAttribute('type', 'email');
        expect(screen.getByTestId('turnstile-widget')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Enviarme el enlace' })).toBeInTheDocument();
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
        render(<AccessInvalid event={{ name: 'Congreso Parques' }} organization={requestProps.organization} requestLinkUrl="/parques/events/congreso/my-submission/request-link" />);

        expect(screen.getByRole('alert')).toHaveTextContent('Pide uno nuevo.');
        expect(screen.getByRole('link', { name: 'Pedir un enlace nuevo' })).toHaveAttribute('href', '/parques/events/congreso/my-submission/request-link');
    });
});

describe('Author/Show page', () => {
    const props = { event: { name: 'Congreso Parques' }, organization: requestProps.organization, submission, sessionMinutes: 60, eventUrl: '/parques/events/congreso?lang=es' };

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

    it('announces a withdrawn proposal', () => {
        render(<Show {...props} submission={{ ...submission, status: 'withdrawn', withdrawnAt: '2027-01-21T10:00:00Z' }} />);

        expect(screen.getByTestId('submission-status')).toHaveTextContent('Retirada');
        expect(screen.getByRole('status')).toHaveTextContent('Retiraste esta propuesta el');
    });

    it('shows a message when there are no extra answers', () => {
        render(<Show {...props} submission={{ ...submission, answers: [] }} />);

        expect(screen.getByText('Sin respuestas adicionales.')).toBeInTheDocument();
    });
});
