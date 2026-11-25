import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Speakers from '@/pages/Events/Speakers';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';
import type { Paginated, ProgramNav, PublicSpeakerSummary } from '@/types/program';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language', privacy_link: 'Aviso de privacidad' },
    languages: { es: 'Español', en: 'English' },
    speakers: {
        title: 'Ponentes',
        description: 'Ponentes de :event.',
        heading: 'Quiénes participan',
        empty_title: 'Los ponentes se anunciarán pronto',
        empty_hint: 'Todavía no hay ponentes publicados.',
        pagination_label: 'Páginas de ponentes',
        role_separator: ', ',
        back_to_event: 'Volver al evento',
        program_link: 'Ver el programa',
    },
    pagination: { previous: 'Página anterior', next: 'Página siguiente', page_of: 'Página :current de :last' },
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
    startsAt: '2026-11-20T15:00:00Z',
    endsAt: '2026-11-21T23:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '24h',
    venueName: null,
    venueAddress: null,
    city: null,
    locale: 'es',
    locales: ['es', 'en'],
    logoUrl: null,
};
const organization = { name: 'Parques de México', slug: 'parques' };
const nav: ProgramNav = { programUrl: '/parques/events/congreso/program', speakersUrl: '/parques/events/congreso/speakers', eventUrl: '/parques/events/congreso' };

function speaker(id: string, name: string): PublicSpeakerSummary {
    return { id, name, company: 'Parques', jobTitle: null, tagline: null, photoUrl: null, url: `/parques/events/congreso/speakers/${id}` };
}

function page(data: PublicSpeakerSummary[], overrides: Partial<Paginated<PublicSpeakerSummary>> = {}): Paginated<PublicSpeakerSummary> {
    return { data, currentPage: 1, lastPage: 1, prevUrl: null, nextUrl: null, ...overrides };
}

function renderSpeakers(props: Partial<Parameters<typeof Speakers>[0]> = {}) {
    return render(<Speakers event={event} organization={organization} speakers={page([speaker('s1', 'Ana Torres'), speaker('s2', 'Luis Pérez')])} nav={nav} privacyUrl={null} {...props} />);
}

describe('Events/Speakers', () => {
    it('CA-029-22: orders the headings h1 (page), h2 (section) and h3 (speakers)', () => {
        renderSpeakers();

        expect(screen.getByRole('heading', { level: 1, name: 'Ponentes' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Quiénes participan' })).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual(['Ana Torres', 'Luis Pérez']);
    });

    it('CA-029-8: links every speaker to the profile and shows the pagination only with several pages', () => {
        const { unmount } = renderSpeakers();

        expect(screen.getByRole('link', { name: 'Ana Torres' })).toHaveAttribute('href', '/parques/events/congreso/speakers/s1');
        expect(screen.queryByRole('navigation', { name: 'Páginas de ponentes' })).not.toBeInTheDocument();
        unmount();

        renderSpeakers({ speakers: page([speaker('s1', 'Ana Torres')], { currentPage: 2, lastPage: 3, prevUrl: '/p', nextUrl: '/p?page=3' }) });

        const pagination = screen.getByRole('navigation', { name: 'Páginas de ponentes' });

        expect(within(pagination).getByRole('link', { name: 'Página anterior' })).toHaveAttribute('href', '/p');
        expect(within(pagination).getByRole('link', { name: 'Página siguiente' })).toHaveAttribute('href', '/p?page=3');
    });

    it('CA-029-18: sets the title with the event and the organization and does not hide a list with content', () => {
        renderSpeakers();

        expect(document.head.querySelector('title')).toHaveTextContent('Ponentes · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[property="og:title"]')).toHaveAttribute('content', 'Ponentes · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
    });

    it('CA-029-17: without visible speakers shows the message and asks not to index, with no list', () => {
        renderSpeakers({ speakers: page([]) });

        expect(screen.getByRole('heading', { level: 2, name: 'Los ponentes se anunciarán pronto' })).toBeInTheDocument();
        expect(screen.getByText('Todavía no hay ponentes publicados.')).toBeInTheDocument();
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
    });

    it('links back to the event and the program, keeps the language switcher and shows the privacy notice only when there is one', () => {
        const { unmount } = renderSpeakers();

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso');
        expect(screen.getByRole('link', { name: 'Ver el programa' })).toHaveAttribute('href', '/parques/events/congreso/program');
        expect(screen.getByRole('navigation', { name: 'Idioma' })).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Aviso de privacidad' })).not.toBeInTheDocument();
        unmount();

        renderSpeakers({ privacyUrl: '/parques/events/congreso/privacy' });
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toHaveAttribute('href', '/parques/events/congreso/privacy');
    });
});
