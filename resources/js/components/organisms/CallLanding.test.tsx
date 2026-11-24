import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import { CallLanding } from '@/components/organisms/CallLanding';
import { createTranslate } from '@/lib/translate';
import type { PublicCall } from '@/types/call';
import type { CallLandingData, CallRouteSlugs } from '@/types/call-landing';

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate({ languages: { es: 'Español', en: 'English' } }), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
}));

const SUBMIT = '/parques/events/congreso/call/ponencias/submit?lang=es';
const SLUGS: CallRouteSlugs = { organization: 'parques', event: 'congreso', call: 'ponencias' };

const call: PublicCall = {
    title: 'Convocatoria de ponencias',
    description: 'Envía tu propuesta',
    submissionType: 'Sesión',
    state: 'open',
    opensAt: '2026-11-24T06:00:00Z',
    closesAt: '2027-01-30T23:00:00Z',
    resultsAt: '2027-03-15T18:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '24h',
    locale: 'es',
    locales: ['es', 'en'],
    tracks: [
        { name: 'Ciencia', description: 'Investigación' },
        { name: 'Gestión', description: null },
    ],
};

const data: CallLandingData = {
    minParticipants: 1,
    maxParticipants: 3,
    formProposal: [
        { name: 'Título de la propuesta', isRequired: true },
        { name: 'Resumen', isRequired: false },
    ],
    formParticipant: [{ name: 'Nombre(s)', isRequired: true }],
    otherCalls: [
        {
            slug: 'posters',
            title: 'Pósters científicos',
            description: 'Comparte tu investigación',
            url: '/parques/events/congreso/call/posters?lang=es',
        },
    ],
};

function renderLanding(
    overrides: Partial<PublicCall> = {},
    submitUrl: string | null = SUBMIT,
    slugs: CallRouteSlugs = SLUGS,
    landing: CallLandingData = data,
) {
    return render(
        <CallLanding
            call={{ ...call, ...overrides }}
            event={{ name: 'Congreso Parques' }}
            data={landing}
            organization={{ name: 'Parques de México', slug: 'parques' }}
            slugs={slugs}
            eventUrl="/parques/events/congreso?lang=es"
            privacyUrl="/parques/events/congreso/privacy"
            submitUrl={submitUrl}
        />,
    );
}

describe('CallLanding', () => {
    it('is built from the data of any call: headline, real dates and the link to the form', () => {
        renderLanding();

        expect(screen.getByRole('heading', { level: 1, name: 'Convocatoria de ponencias' })).toBeInTheDocument();
        expect(screen.getByText('Envía tu propuesta')).toBeInTheDocument();
        expect(screen.getByText('24 de noviembre de 2026')).toBeInTheDocument();
        expect(screen.getByText('30 de enero de 2027')).toBeInTheDocument();
        expect(screen.getByText('15 de marzo de 2027')).toBeInTheDocument();
        screen.getAllByRole('link', { name: 'Enviar propuesta' }).forEach((link) => expect(link).toHaveAttribute('href', SUBMIT));
    });

    it('shows the axes of the event, what the form asks for and the other calls of the event', () => {
        renderLanding();

        expect(within(document.getElementById('ejes') as HTMLElement).getAllByRole('heading', { level: 3 })).toHaveLength(2);
        expect(screen.getByText('Resumen')).toBeInTheDocument();
        expect(screen.getByText('Nombre(s)')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Pósters científicos/ })).toHaveAttribute(
            'href',
            '/parques/events/congreso/call/posters?lang=es',
        );
        expect(screen.getByText('Ver convocatoria')).toBeInTheDocument();
    });

    it('leaves out the sections without data: no axes, no form preview, no results card, no other calls', () => {
        renderLanding({ tracks: [], resultsAt: undefined }, SUBMIT, SLUGS, {
            ...data,
            formProposal: [],
            formParticipant: [],
            otherCalls: [],
        });

        expect(document.getElementById('ejes')).toBeNull();
        expect(screen.queryByText('Lo que te pide el formulario')).not.toBeInTheDocument();
        expect(screen.queryByText('Resultados', { selector: 'span' })).not.toBeInTheDocument();
        expect(screen.queryByText('Ver convocatoria')).not.toBeInTheDocument();
    });

    it('the ANPR calls keep their own texts and covers', () => {
        renderLanding(
            {},
            SUBMIT,
            { organization: 'convocatorias', event: 'congreso-parques-2027', call: 'registro-de-poster-cientifico' },
            {
                ...data,
                otherCalls: [{ slug: 'registro-de-sesiones-educativas', title: 'Sesiones', description: null, url: '/sesiones' }],
            },
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Exhibe tu póster científico' })).toBeInTheDocument();
        expect(screen.getByText('No necesitas ser miembro de la ANPR México')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Postular sesión/ })).toHaveAttribute('href', '/sesiones');
    });

    it('without the form link the buttons lose their link and say why', () => {
        renderLanding({ state: 'closed' }, null);

        expect(screen.getAllByText('Convocatoria cerrada').length).toBeGreaterThan(0);
        expect(screen.queryByRole('link', { name: 'Enviar propuesta' })).not.toBeInTheDocument();
    });

    it('keeps the back to event link, the privacy notice, the own links and the other languages, and can hide the back link', () => {
        const { unmount } = renderLanding({ extraLinks: [{ label: 'Bases', url: 'https://example.org/bases' }] });

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Bases' })).toHaveAttribute('href', 'https://example.org/bases');
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute('href', '?lang=en');
        unmount();

        renderLanding({ showBackLink: false });

        expect(screen.queryByRole('link', { name: 'Volver al evento' })).not.toBeInTheDocument();
    });
});
