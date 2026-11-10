import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Show from '@/pages/Calls/Show';
import { createTranslate } from '@/lib/translate';
import type { PublicCall } from '@/types/call';

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
        state: { scheduled: 'Próximamente', open: 'Abierta', paused: 'En pausa', closed: 'Cerrada' },
        state_hint: {
            scheduled: 'La convocatoria aún no abre.',
            open: 'La convocatoria recibe propuestas.',
            paused: 'La recepción de propuestas está en pausa por el momento.',
            closed: 'La convocatoria ya no recibe propuestas.',
        },
    },
    languages: { es: 'Español', en: 'English' },
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
    locale: 'es',
    locales: ['es', 'en'],
    tracks: [
        { name: 'Ciencia', description: 'Investigación' },
        { name: 'Gestión', description: null },
    ],
};
const organization = { name: 'Parques de México', slug: 'parques' };
const event = { name: 'Congreso Parques' };

function renderPage(overrides: Partial<PublicCall> = {}, privacyUrl: string | null = null) {
    return render(
        <Show
            call={{ ...call, ...overrides }}
            event={event}
            organization={organization}
            eventUrl="/parques/events/congreso?lang=es"
            privacyUrl={privacyUrl}
        />,
    );
}

describe('Calls/Show page', () => {
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

    it('shows the dates in the timezone of the event', () => {
        renderPage();

        // 15:00 UTC = 09:00 en Ciudad de México (UTC-6 en enero).
        expect(screen.getByText(/10 de enero de 2027/)).toHaveTextContent(/9:00/);
        expect(screen.getByText(/15 de febrero de 2027/)).toHaveTextContent(/17:00|5:00/);
        expect(screen.getByText('America/Mexico_City')).toBeInTheDocument();
    });

    it('hides the results date when the call has none', () => {
        renderPage({ resultsAt: undefined });

        expect(screen.queryByText('Resultados')).not.toBeInTheDocument();
    });

    it('shows the results date when it is defined', () => {
        renderPage();

        expect(screen.getByText('Resultados')).toBeInTheDocument();
        expect(screen.getByText(/1 de marzo de 2027/)).toBeInTheDocument();
    });

    it('lists the tracks in order with their description', () => {
        renderPage();

        const list = screen.getByRole('list', { name: 'Ejes temáticos' });
        const items = within(list).getAllByRole('listitem');

        expect(items).toHaveLength(2);
        expect(items[0]).toHaveTextContent('Ciencia');
        expect(items[0]).toHaveTextContent('Investigación');
        expect(items[1]).toHaveTextContent('Gestión');
    });

    it('shows an empty state when there are no tracks', () => {
        renderPage({ tracks: [] });

        expect(screen.getByText('Esta convocatoria aún no tiene ejes temáticos.')).toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Ejes temáticos' })).not.toBeInTheDocument();
    });

    it('links back to the event, and to the privacy notice only when there is one', () => {
        const { unmount } = renderPage();

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
        expect(screen.queryByRole('link', { name: 'Aviso de privacidad' })).not.toBeInTheDocument();

        unmount();
        renderPage({}, '/parques/events/congreso/privacy');

        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toHaveAttribute('href', '/parques/events/congreso/privacy');
    });

    it('offers the other languages of the call', () => {
        renderPage();

        const nav = screen.getByRole('navigation', { name: 'Idioma' });

        expect(within(nav).getByRole('link', { name: 'Ver en English' })).toHaveAttribute('href', '?lang=en');
    });

    it('has no submit button: the form belongs to a later plan', () => {
        renderPage();

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
