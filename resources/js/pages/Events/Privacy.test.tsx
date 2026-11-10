import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Privacy from '@/pages/Events/Privacy';
import { createTranslate } from '@/lib/translate';
import type { PrivacyNotice, PrivacyPageEvent } from '@/types/event';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language' },
    privacy: {
        title: 'Aviso de privacidad',
        version: 'Versión :version',
        published_on: 'Publicado el :date',
        back_to_event: 'Volver al evento',
        shown_in_base_language: 'Este aviso se muestra en el idioma base del evento.',
    },
    languages: { es: 'Español', en: 'English' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
}));

const event: PrivacyPageEvent = { name: 'Congreso Parques', locale: 'es', locales: ['es', 'en'] };
const eventUrl = "/parques/events/congreso?lang=es";
const organization = { name: 'Parques de México', slug: 'parques' };
const notice: PrivacyNotice = {
    version: 3,
    publishedAt: '2026-11-10T23:30:00Z',
    body: '<p>Tratamos tus datos con <strong>cuidado</strong>.</p><script>window.pwned = 1</script>',
    locale: 'es',
};

describe('Events/Privacy page', () => {
    it('shows the title, the event name, the version and the publication date in UTC', () => {
        render(<Privacy event={event} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Aviso de privacidad' })).toBeInTheDocument();
        expect(screen.getByText('Congreso Parques')).toBeInTheDocument();
        expect(screen.getByText('Versión 3')).toBeInTheDocument();
        expect(screen.getByText(/Publicado el 10 de noviembre de 2026/)).toHaveTextContent(/23:30.*UTC/);
    });

    it('shows the body as rich text, without executing anything', () => {
        const { container } = render(<Privacy event={event} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.getByText('cuidado').tagName).toBe('STRONG');
        expect(container.querySelector('main script')).toBeNull();
        expect(container).not.toHaveTextContent('pwned');
    });

    it('links back to the event page keeping the language', () => {
        render(<Privacy event={event} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
    });

    it('offers the other languages of the event', () => {
        render(<Privacy event={event} organization={organization} notice={notice} eventUrl={eventUrl} />);

        const nav = screen.getByRole('navigation', { name: 'Idioma' });

        expect(within(nav).getByRole('link', { name: 'Ver en English' })).toHaveAttribute('href', '?lang=en');
    });

    it('hides the language switcher when the event has a single language', () => {
        render(<Privacy event={{ ...event, locales: ['es'] }} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.queryByRole('navigation', { name: 'Idioma' })).not.toBeInTheDocument();
    });

    it('says discreetly that the text is in the base language when it differs from the page language', () => {
        render(<Privacy event={{ ...event, locale: 'en' }} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.getByText('Este aviso se muestra en el idioma base del evento.')).toBeInTheDocument();
    });

    it('does not show that remark when the text is in the language of the page', () => {
        render(<Privacy event={event} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(screen.queryByText('Este aviso se muestra en el idioma base del evento.')).not.toBeInTheDocument();
    });

    it('marks the text with its real language', () => {
        const { container } = render(<Privacy event={{ ...event, locale: 'en' }} organization={organization} notice={notice} eventUrl={eventUrl} />);

        expect(container.querySelector('main [lang="es"]')).not.toBeNull();
    });
});
