import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Program from '@/pages/Events/Program';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';
import type { Paginated, ProgramNav, PublicActivitySummary, PublicCategory } from '@/types/program';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language', privacy_link: 'Aviso de privacidad' },
    languages: { es: 'Español', en: 'English' },
    program: {
        title: 'Programa',
        description: 'Programa de :event.',
        timezone_note: 'Horario del evento: :zone',
        days_label: 'Días del programa',
        filter_label: 'Filtrar por categoría',
        no_results: 'Ninguna actividad de esta categoría en esta página.',
        empty_title: 'El programa se publicará pronto',
        empty_hint: 'Todavía no hay actividades programadas.',
        back_to_event: 'Volver al evento',
        until: 'Termina el :date',
        time_range: ':start – :end',
        speakers: 'Ponentes',
        pagination_label: 'Páginas del programa',
        limited_seats: 'Cupo limitado',
    },
    pagination: {
        previous: 'Página anterior',
        next: 'Página siguiente',
        page_of: 'Página :current de :last',
    },
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
const talleres: PublicCategory = { id: 'c1', name: 'Talleres' };
const charlas: PublicCategory = { id: 'c2', name: 'Charlas' };

function activity(id: string, title: string, categories: PublicCategory[], overrides: Partial<PublicActivitySummary> = {}): PublicActivitySummary {
    return {
        id,
        title,
        summary: null,
        date: '2026-11-20',
        endDate: '2026-11-20',
        startTime: '09:00',
        endTime: '10:00',
        categories,
        speakers: [],
        iconUrl: null,
        limitedSeats: false,
        url: `/parques/events/congreso/activities/${id}`,
        ...overrides,
    };
}

function page(data: PublicActivitySummary[], overrides: Partial<Paginated<PublicActivitySummary>> = {}): Paginated<PublicActivitySummary> {
    return { data, currentPage: 1, lastPage: 1, prevUrl: null, nextUrl: null, ...overrides };
}

function renderProgram(props: Partial<Parameters<typeof Program>[0]> = {}) {
    return render(
        <Program
            event={event}
            organization={organization}
            days={['2026-11-20', '2026-11-21']}
            day="2026-11-20"
            activities={page([activity('a1', 'Taller de huertos', [talleres]), activity('a2', 'Charla de biodiversidad', [charlas])])}
            categories={[talleres, charlas]}
            nav={nav}
            privacyUrl={null}
            {...props}
        />,
    );
}

describe('Events/Program', () => {
    it('CA-035-24: marks with a limited quota badge only the activities that have a quota, with no number', () => {
        renderProgram({
            activities: page([activity('a1', 'Taller de huertos', [talleres], { limitedSeats: true }), activity('a2', 'Charla de biodiversidad', [charlas])]),
        });

        expect(screen.getAllByText('Cupo limitado')).toHaveLength(1);
        expect(screen.getByText('Cupo limitado').closest('article')).toHaveTextContent('Taller de huertos');
        expect(screen.getByText('Charla de biodiversidad').closest('article')).not.toHaveTextContent('Cupo limitado');
    });

    it('CA-029-22: orders the headings h1 (page), h2 (day) and h3 (activities)', () => {
        renderProgram();

        expect(screen.getByRole('heading', { level: 1, name: 'Programa' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: /20 de noviembre de 2026/ })).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual(['Taller de huertos', 'Charla de biodiversidad']);
    });

    it('CA-029-12: shows the time zone of the event once, in the header', () => {
        renderProgram();

        expect(screen.getAllByText(/Horario del evento: America\/Mexico_City \(GMT-6\)/)).toHaveLength(1);
    });

    it('CA-029-12: shows the hours as the event writes them, in its time format', () => {
        renderProgram();

        expect(screen.getAllByText('09:00 – 10:00')).toHaveLength(2);
    });

    it('CA-029-1: lists the days and marks the one being shown', () => {
        renderProgram();

        const links = within(screen.getByRole('navigation', { name: 'Días del programa' })).getAllByRole('link');

        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('aria-current', 'page');
        expect(links[1]).not.toHaveAttribute('aria-current');
        expect(links[1]).toHaveAttribute('href', '/parques/events/congreso/program?day=2026-11-21');
    });

    it('CA-029-21: filters the activities of the day by category and releases the filter', () => {
        renderProgram();

        const workshops = screen.getByRole('button', { name: 'Talleres' });

        fireEvent.click(workshops);

        expect(workshops).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('heading', { level: 3, name: 'Taller de huertos' })).toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 3, name: 'Charla de biodiversidad' })).not.toBeInTheDocument();

        fireEvent.click(workshops);

        expect(workshops).toHaveAttribute('aria-pressed', 'false');
        expect(screen.getAllByRole('heading', { level: 3 })).toHaveLength(2);
    });

    it('CA-029-21: says so when the chosen category has no activity on this page', () => {
        renderProgram({ activities: page([activity('a1', 'Taller de huertos', [talleres])]), categories: [talleres, charlas] });

        fireEvent.click(screen.getByRole('button', { name: 'Charlas' }));

        expect(screen.getByRole('status')).toHaveTextContent('Ninguna actividad de esta categoría en esta página.');
    });

    it('CA-029-13: shows the end date of an activity that crosses midnight', () => {
        renderProgram({ activities: page([activity('a1', 'Noche', [], { endDate: '2026-11-21', startTime: '22:00', endTime: '02:00' })]), categories: [] });

        expect(screen.getByText(/Termina el .*21 de noviembre/)).toBeInTheDocument();
    });

    it('shows the previous and next page links with the position when the day has several pages', () => {
        renderProgram({ activities: page([activity('a1', 'Uno', [])], { currentPage: 2, lastPage: 3, prevUrl: '/p?day=2026-11-20', nextUrl: '/p?day=2026-11-20&page=3' }) });

        const pagination = screen.getByRole('navigation', { name: 'Páginas del programa' });

        expect(within(pagination).getByRole('link', { name: 'Página anterior' })).toHaveAttribute('href', '/p?day=2026-11-20');
        expect(within(pagination).getByRole('link', { name: 'Página siguiente' })).toHaveAttribute('href', '/p?day=2026-11-20&page=3');
        expect(within(pagination).getByText('Página 2 de 3')).toBeInTheDocument();
    });

    it('does not draw pagination when everything fits in one page', () => {
        renderProgram();

        expect(screen.queryByRole('navigation', { name: 'Páginas del programa' })).not.toBeInTheDocument();
    });

    it('links back to the event and keeps the language switcher', () => {
        renderProgram();

        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso');
        expect(screen.getByRole('navigation', { name: 'Idioma' })).toBeInTheDocument();
    });

    it('links the privacy notice only when the event has one', () => {
        const { unmount } = renderProgram();

        expect(screen.queryByRole('link', { name: 'Aviso de privacidad' })).not.toBeInTheDocument();
        unmount();

        renderProgram({ privacyUrl: '/parques/events/congreso/privacy' });
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' })).toHaveAttribute('href', '/parques/events/congreso/privacy');
    });

    it('CA-029-18: sets the title with the event and the organization and does not hide a program with content', () => {
        renderProgram();

        // React 19 lleva `<title>` y `<meta>` al `<head>` del documento.
        expect(document.head.querySelector('title')).toHaveTextContent('Programa · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[property="og:title"]')).toHaveAttribute('content', 'Programa · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
    });

    it('CA-029-17: without visible activities shows the message that the program will be published soon and asks not to index', () => {
        renderProgram({ days: [], day: null, activities: page([]), categories: [] });

        expect(screen.getByRole('heading', { level: 2, name: 'El programa se publicará pronto' })).toBeInTheDocument();
        expect(screen.getByText('Todavía no hay actividades programadas.')).toBeInTheDocument();
        expect(document.head.querySelector('meta[name="robots"]')).toHaveAttribute('content', 'noindex');
        expect(screen.queryByRole('list')).not.toBeInTheDocument();
        expect(screen.queryByRole('navigation', { name: 'Días del programa' })).not.toBeInTheDocument();
    });
});
