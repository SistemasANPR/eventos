import { render, screen, within } from '@testing-library/react';

import { EventHero } from '@/components/organisms/EventHero';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';

const translations = {
    event: {
        when: 'Fechas y horario',
        where: 'Sede',
        timezone: 'Zona horaria',
        language: 'Idioma',
        language_option: 'Ver en :language',
        no_venue: 'Sede por confirmar',
    },
    languages: { es: 'Español', en: 'English' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

const event: PublicEvent = {
    name: 'Congreso Parques',
    description: 'Una descripción',
    startsAt: '2027-03-10T15:00:00Z',
    endsAt: '2027-03-12T23:00:00Z',
    timezone: 'America/Mexico_City',
    venueName: 'Centro de Convenciones',
    venueAddress: null,
    city: 'Ciudad de México',
    locale: 'es',
    locales: ['es', 'en'],
};

describe('EventHero', () => {
    it('shows the name as the main heading', () => {
        render(<EventHero event={event} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Congreso Parques' })).toBeInTheDocument();
    });

    it('shows the description, the venue and the time zone', () => {
        render(<EventHero event={event} />);

        expect(screen.getByText('Una descripción')).toBeInTheDocument();
        expect(screen.getByText('Centro de Convenciones, Ciudad de México')).toBeInTheDocument();
        expect(screen.getByText('America/Mexico_City')).toBeInTheDocument();
    });

    it('shows the dates in the time zone of the event, formatted for the language', () => {
        render(<EventHero event={event} />);

        const dates = screen.getByText(/marzo/);

        expect(dates).toHaveTextContent(/9:00/);
        expect(dates).not.toHaveTextContent(/15:00/);
    });

    it('omits the description when there is none', () => {
        render(<EventHero event={{ ...event, description: null }} />);

        expect(screen.queryByText('Una descripción')).not.toBeInTheDocument();
    });

    it('says the venue is to be confirmed when there is none', () => {
        render(<EventHero event={{ ...event, venueName: null, city: null }} />);

        expect(screen.getByText('Sede por confirmar')).toBeInTheDocument();
    });

    it('offers the other languages and marks the current one', () => {
        render(<EventHero event={event} />);

        const nav = screen.getByRole('navigation', { name: 'Idioma' });

        expect(within(nav).getByRole('link', { name: 'Ver en Español' })).toHaveAttribute('aria-current', 'true');
        expect(within(nav).getByRole('link', { name: 'Ver en English' })).toHaveAttribute('href', '?lang=en');
    });

    it('hides the language switcher when the event has a single language', () => {
        render(<EventHero event={{ ...event, locales: ['es'] }} />);

        expect(screen.queryByRole('navigation', { name: 'Idioma' })).not.toBeInTheDocument();
    });
});
