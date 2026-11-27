import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Show from '@/pages/Calls/Show';
import { createTranslate } from '@/lib/translate';
import type { PublicCall } from '@/types/call';
import type { CallLandingData, CallRouteSlugs } from '@/types/call-landing';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language', privacy_link: 'Aviso de privacidad' },
    call: {
        type: 'Tipo de propuesta',
        status: 'Estado',
        opens_at: 'Apertura',
        closes_at: 'Cierre',
        results_at: 'Resultados',
        timezone: 'Zona horaria',
        tracks: 'Ejes temáticos',
        no_tracks: 'Esta convocatoria aún no tiene ejes temáticos.',
        back_to_event: 'Volver al evento',
        already_submitted: '¿Ya enviaste tu propuesta? Consúltala o edítala',
        state: { scheduled: 'Próximamente', open: 'Abierta', paused: 'En pausa', closed: 'Cerrada' },
        state_hint: {
            scheduled: 'La convocatoria aún no abre.',
            open: 'La convocatoria recibe propuestas.',
            paused: 'La recepción de propuestas está en pausa por el momento.',
            closed: 'La convocatoria ya no recibe propuestas.',
        },
    },
    languages: { es: 'Español', en: 'English' },
    submit: { send_action: 'Enviar propuesta' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
}));

const call: PublicCall = {
    title: 'Convocatoria de ponencias',
    description: 'Envía tu propuesta',
    submissionType: 'Sesión',
    state: 'open',
    opensAt: '2027-01-10T15:00:00Z',
    closesAt: '2027-02-15T23:00:00Z',
    resultsAt: '2027-03-01T18:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '12h',
    locale: 'es',
    locales: ['es', 'en'],
    tracks: [
        { name: 'Ciencia', description: 'Investigación' },
        { name: 'Gestión', description: null },
    ],
};
const organization = { name: 'Parques de México', slug: 'parques' };
const event = { name: 'Congreso Parques' };

function renderPage(
    overrides: Partial<PublicCall> = {},
    privacyUrl: string | null = null,
    submitUrl: string | null = null,
    slugs?: CallRouteSlugs,
    landing?: CallLandingData,
) {
    return render(
        <Show
            slugs={slugs}
            landing={landing}
            call={{ ...call, ...overrides }}
            event={event}
            organization={organization}
            eventUrl="/parques/events/congreso?lang=es"
            privacyUrl={privacyUrl}
            submitUrl={submitUrl}
        />,
    );
}

describe('Calls/Show page', () => {
    it('uses the landing in Spanish when the server sends its data, and the usual page in other languages or without it', () => {
        const slugs = { organization: 'parques', event: 'congreso', call: 'ponencias' };
        const landing = { minParticipants: 1, maxParticipants: 5, formProposal: [], formParticipant: [], otherCalls: [] };
        const { unmount } = renderPage({}, null, null, slugs, landing);

        expect(screen.getByRole('heading', { level: 1, name: 'Convocatoria de ponencias' })).toBeInTheDocument();
        expect(document.querySelector('.cl')).not.toBeNull();
        unmount();

        const english = renderPage({ locale: 'en' }, null, null, slugs, landing);

        expect(document.querySelector('.cl')).toBeNull();
        english.unmount();

        renderPage();

        expect(document.querySelector('.cl')).toBeNull();
    });

    it('shows the title as the only h1, the event name and the description', () => {
        renderPage();

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'Convocatoria de ponencias' })).toBeInTheDocument();
        expect(screen.getByText('Congreso Parques')).toBeInTheDocument();
        expect(screen.getByText('Envía tu propuesta')).toBeInTheDocument();
    });

    it.each([
        ['scheduled', 'Próximamente', 'La convocatoria aún no abre.'],
        ['open', 'Abierta', 'La convocatoria recibe propuestas.'],
        ['paused', 'En pausa', 'La recepción de propuestas está en pausa por el momento.'],
        ['closed', 'Cerrada', 'La convocatoria ya no recibe propuestas.'],
    ] as const)('shows the %s state with its badge and hint', (state, label, hint) => {
        renderPage({ state });

        expect(screen.getByTestId('call-state')).toHaveTextContent(label);
        expect(screen.getByText(hint)).toBeInTheDocument();
    });

    it('shows opening, closing and results dates in the timezone of the event', () => {
        renderPage();

        // 15:00 UTC = 09:00 en Ciudad de México (UTC-6 en enero).
        expect(screen.getByText('Apertura')).toBeInTheDocument();
        expect(screen.getByText('Cierre')).toBeInTheDocument();
        expect(screen.getByText(/10 de enero de 2027/)).toHaveTextContent(/9:00/);
        expect(screen.getByText(/15 de febrero de 2027/)).toHaveTextContent(/17:00|5:00/);
        expect(screen.getByText('Resultados')).toBeInTheDocument();
        expect(screen.getByText(/1 de marzo de 2027/)).toBeInTheDocument();
    });

    it('hides the results date when the call has none', () => {
        renderPage({ resultsAt: undefined });

        expect(screen.queryByText('Resultados')).not.toBeInTheDocument();
        expect(screen.getByText('Apertura')).toBeInTheDocument();
    });

    it('does not show the category, the timezone nor the tracks', () => {
        renderPage();

        expect(screen.queryByText('Tipo de propuesta')).not.toBeInTheDocument();
        expect(screen.queryByText('Sesión')).not.toBeInTheDocument();
        expect(screen.queryByText('Zona horaria')).not.toBeInTheDocument();
        expect(screen.queryByText('America/Mexico_City')).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 2 })).not.toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Ejes temáticos' })).not.toBeInTheDocument();
        expect(screen.queryByText('Ciencia')).not.toBeInTheDocument();
    });
    it('links back to the event, and to the privacy notice only when there is one', () => {
        const { unmount } = renderPage();

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
        expect(screen.queryByRole('link', { name: 'Aviso de privacidad' })).not.toBeInTheDocument();

        unmount();
        renderPage({}, '/parques/events/congreso/privacy');

        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toHaveAttribute('href', '/parques/events/congreso/privacy');
    });

    it('hides the back link when the organizer turns it off, and keeps the other links', () => {
        renderPage({ showBackLink: false }, '/parques/events/congreso/privacy');

        expect(screen.queryByRole('link', { name: 'Volver al evento' })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toBeInTheDocument();
    });

    it('shows every custom link of the organizer, in order and with safe rel', () => {
        renderPage({
            extraLinks: [
                { label: 'Sitio del congreso', url: 'https://example.test/congreso' },
                { label: 'Programa', url: 'https://example.test/programa' },
            ],
        });

        const link = screen.getByRole('link', { name: 'Sitio del congreso' });

        expect(link).toHaveAttribute('href', 'https://example.test/congreso');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(screen.getByRole('link', { name: 'Programa' })).toHaveAttribute('href', 'https://example.test/programa');
    });

    it('offers the other languages of the call', () => {
        renderPage();

        const nav = screen.getByRole('navigation', { name: 'Idioma' });

        expect(within(nav).getByRole('link', { name: 'Ver en English' })).toHaveAttribute('href', '?lang=en');
    });

    it('CA-026-11: shows no language selector when the call has a single effective language', () => {
        renderPage({ locales: ['es'] });

        expect(screen.queryByRole('navigation', { name: 'Idioma' })).not.toBeInTheDocument();
    });

    it('CA-006-55: offers the link to the submission form only when the server provides it', () => {
        const view = renderPage();
        expect(screen.queryByRole('link', { name: 'Enviar propuesta' })).not.toBeInTheDocument();
        view.unmount();

        renderPage({}, null, '/parques/events/congreso/call/ponencias/submit?lang=es');

        expect(screen.getByRole('link', { name: 'Enviar propuesta' })).toHaveAttribute(
            'href',
            '/parques/events/congreso/call/ponencias/submit?lang=es',
        );
    });

    it('has no submit button element: the entry to the form is a link', () => {
        renderPage();

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});

describe('Calls/Show page: logo (plan 027)', () => {
    it('CA-027-13: shows the logo with the event name as alt text and nothing without it', () => {
        const { unmount } = render(
            <Show
                call={call}
                event={{ ...event, logoUrl: '/parques/events/congreso/logo?v=ab12' }}
                organization={organization}
                eventUrl="/e"
                privacyUrl={null}
            />,
        );

        expect(screen.getByRole('img', { name: 'Congreso Parques' })).toHaveAttribute('src', '/parques/events/congreso/logo?v=ab12');
        unmount();

        render(<Show call={call} event={{ ...event, logoUrl: null }} organization={organization} eventUrl="/e" privacyUrl={null} />);

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });
});

describe('Calls/Show page: way back to a sent proposal (plan 034)', () => {
    const authorRequestUrl = '/parques/events/congreso/my-submission/request-link?lang=es';

    it('CA-034-12: links to the request-link page in the usual page, in any state of the call', () => {
        const { unmount } = render(
            <Show call={{ ...call, state: 'closed' }} event={event} organization={organization} eventUrl="/e" privacyUrl={null} authorRequestUrl={authorRequestUrl} />,
        );

        expect(screen.getByRole('link', { name: '¿Ya enviaste tu propuesta? Consúltala o edítala' })).toHaveAttribute('href', authorRequestUrl);
        unmount();

        render(<Show call={call} event={event} organization={organization} eventUrl="/e" privacyUrl={null} />);

        expect(screen.queryByRole('link', { name: '¿Ya enviaste tu propuesta? Consúltala o edítala' })).not.toBeInTheDocument();
    });

    it('CA-034-12: links to the request-link page in the Spanish landing too', () => {
        const slugs = { organization: 'parques', event: 'congreso', call: 'ponencias' };
        const landing = { minParticipants: 1, maxParticipants: 5, formProposal: [], formParticipant: [], otherCalls: [] };
        render(
            <Show
                slugs={slugs}
                landing={landing}
                call={call}
                event={event}
                organization={organization}
                eventUrl="/e"
                privacyUrl={null}
                authorRequestUrl={authorRequestUrl}
            />,
        );

        expect(screen.getByRole('link', { name: '¿Ya enviaste tu propuesta? Consúltala o edítala' })).toHaveAttribute('href', authorRequestUrl);
    });

    it('CA-034-12: links to the request-link page in the own landing of the call (plan 033) too, and not when it is not offered', () => {
        const callLanding = { blocks: [] };
        const { unmount } = render(
            <Show call={call} event={event} organization={organization} eventUrl="/e" privacyUrl={null} callLanding={callLanding} authorRequestUrl={authorRequestUrl} />,
        );

        expect(screen.getByRole('link', { name: '¿Ya enviaste tu propuesta? Consúltala o edítala' })).toHaveAttribute('href', authorRequestUrl);
        unmount();

        render(<Show call={call} event={event} organization={organization} eventUrl="/e" privacyUrl={null} callLanding={callLanding} />);

        expect(screen.queryByRole('link', { name: '¿Ya enviaste tu propuesta? Consúltala o edítala' })).not.toBeInTheDocument();
    });
});
