import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import Show from '@/pages/Events/Show';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';
import type { PublicLanding } from '@/types/landing';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: {
        when: 'Fechas y horario',
        where: 'Sede',
        timezone: 'Zona horaria',
        language: 'Idioma',
        language_option: 'Ver en :language',
        no_venue: 'Sede por confirmar',
        privacy_link: 'Aviso de privacidad',
    },
    languages: { es: 'Español', en: 'English' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title, children }: { title?: string; children?: ReactNode }): ReactNode => (
        <>
            <title>{title}</title>
            {children}
        </>
    ),
}));

const event: PublicEvent = {
    name: 'Congreso Parques',
    description: 'Una descripción',
    startsAt: '2027-03-10T15:00:00Z',
    endsAt: '2027-03-12T23:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '12h',
    venueName: 'Centro de Convenciones',
    venueAddress: null,
    city: 'Ciudad de México',
    locale: 'es',
    locales: ['es', 'en'],
    logoUrl: null,
};
const organization = { name: 'Parques de México', slug: 'parques' };

const landing: PublicLanding = {
    blocks: [
        {
            id: 'h',
            type: 'hero',
            title: 'Bienvenidos al congreso',
            subtitle: null,
            imageUrl: null,
            imageAlt: null,
            button: null,
            tone: 'light',
            align: 'start',
        },
    ],
};

describe('Events/Show page', () => {
    it('CA-022-21: draws the landing blocks when a published landing arrives', () => {
        render(<Show event={event} organization={organization} privacyUrl="/privacy" landing={landing} />);

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Bienvenidos al congreso',
            }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toBeInTheDocument();
    });

    it('CA-022-22: keeps the current page (EventHero) when there is no landing', () => {
        render(<Show event={event} organization={organization} privacyUrl={null} landing={null} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Congreso Parques' })).toBeInTheDocument();
        expect(screen.getByText('Fechas y horario')).toBeInTheDocument();
    });

    it('CA-022-22: keeps the current page when the landing prop is absent', () => {
        render(<Show event={event} organization={organization} privacyUrl={null} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Congreso Parques' })).toBeInTheDocument();
    });
});

describe('Events/Show page: logo (plan 027)', () => {
    const logoUrl = '/parques/events/congreso/logo?v=ab12cd34';

    it('CA-027-13: shows the logo with the event name as alt text, with and without landing', () => {
        const { unmount } = render(<Show event={{ ...event, logoUrl }} organization={organization} privacyUrl={null} />);

        expect(screen.getByRole('img', { name: 'Congreso Parques' })).toHaveAttribute('src', logoUrl);
        unmount();

        render(<Show event={{ ...event, logoUrl }} organization={organization} privacyUrl={null} landing={landing} />);

        expect(screen.getByRole('img', { name: 'Congreso Parques' })).toHaveAttribute('src', logoUrl);
    });

    it('CA-027-13: without a logo there is no image', () => {
        render(<Show event={{ ...event, logoUrl: null }} organization={organization} privacyUrl={null} />);

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });
});
