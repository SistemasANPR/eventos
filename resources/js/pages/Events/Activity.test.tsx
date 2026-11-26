import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import Activity from '@/pages/Events/Activity';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';
import type { ProgramNav, PublicActivity } from '@/types/program';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language', privacy_link: 'Aviso de privacidad' },
    languages: { es: 'Español', en: 'English' },
    program: { until: 'Termina el :date', time_range: ':start – :end', limited_seats: 'Cupo limitado' },
    speakers: { title: 'Ponentes', role_separator: ', ' },
    activity: {
        description: ':title: :event.',
        on_date: ':date, :range',
        description_heading: 'Descripción',
        speakers: 'Ponentes',
        no_speakers: 'Ponentes por confirmar.',
        categories: 'Categorías de la actividad',
        back_to_program: 'Volver al programa',
        details_heading: 'Lugar y participación',
        seats: 'Cupo',
        seats_value: { one: ':count persona', other: ':count personas' },
        place: 'Lugar',
        capacity: 'Capacidad del lugar',
        map_link: 'Ver mapa',
        map_link_label: 'Ver mapa de :place (se abre en una pestaña nueva)',
        meeting_point: 'Punto de reunión',
        transport: 'Transporte',
        contacts: 'Responsables',
        call_phone: 'Llamar a :name',
        write_email: 'Escribir a :name',
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

const activity: PublicActivity = {
    id: 'a1',
    title: 'Taller de huertos',
    summary: 'Aprende a plantar.',
    date: '2026-11-20',
    endDate: '2026-11-20',
    startTime: '23:30',
    endTime: '23:59',
    categories: [{ id: 'c1', name: 'Talleres' }],
    speakers: [
        { id: 's1', name: 'Ana Torres', company: 'Parques', jobTitle: null, tagline: null, photoUrl: null, url: '/parques/events/congreso/speakers/s1' },
        { id: 's2', name: 'Luis Pérez', company: null, jobTitle: null, tagline: null, photoUrl: null, url: '/parques/events/congreso/speakers/s2' },
    ],
    iconUrl: null,
    limitedSeats: false,
    url: '/parques/events/congreso/activities/a1',
    descriptionHtml: '<p>Una <em>larga</em> descripción.</p>',
    imageUrl: '/parques/events/congreso/activities/a1/image',
    seats: null,
    place: null,
    meetingPoint: null,
    transport: null,
    contacts: [],
};

function renderActivity(props: Partial<Parameters<typeof Activity>[0]> = {}) {
    return render(<Activity event={event} organization={organization} activity={activity} nav={nav} {...props} />);
}

describe('Events/Activity', () => {
    it('CA-029-22: orders the headings h1 (title), h2 (sections) and h3 (speakers)', () => {
        renderActivity();

        expect(screen.getByRole('heading', { level: 1, name: 'Taller de huertos' })).toBeInTheDocument();
        expect(screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)).toEqual(['Descripción', 'Ponentes']);
        expect(screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual(['Ana Torres', 'Luis Pérez']);
    });

    it('CA-029-12: shows the date and the local time of the event in its 24 hour format without shifting it', () => {
        renderActivity();

        expect(screen.getByText(/20 de noviembre de 2026, 23:30 – 23:59/)).toBeInTheDocument();
    });

    it('CA-029-12: shows the hours in 12 hour format when the event uses it', () => {
        renderActivity({ event: { ...event, timeFormat: '12h' } });

        expect(screen.getByText(/20 de noviembre de 2026, 11:30\s?p\.?\s?m\.? – 11:59\s?p\.?\s?m\.?/i)).toBeInTheDocument();
    });

    it('CA-029-13: shows the end date only when the activity ends on another day', () => {
        const { unmount } = renderActivity();

        expect(screen.queryByText(/Termina el/)).not.toBeInTheDocument();
        unmount();

        renderActivity({ activity: { ...activity, endDate: '2026-11-21', startTime: '22:00', endTime: '02:00' } });
        expect(screen.getByText(/Termina el .*21 de noviembre/)).toBeInTheDocument();
    });

    it('CA-029-10: lists the speakers in the order received, each linking to its profile', () => {
        renderActivity();

        const links = screen.getAllByRole('link').filter((link) => link.getAttribute('href')?.includes('/speakers/'));

        expect(links.map((link) => link.textContent)).toEqual(['Ana Torres', 'Luis Pérez']);
        expect(links[0]).toHaveAttribute('href', '/parques/events/congreso/speakers/s1');
    });

    it('shows the categories, the sanitized description and the image as decorative', () => {
        const { container } = renderActivity();

        expect(screen.getByRole('list', { name: 'Categorías de la actividad' })).toHaveTextContent('Talleres');
        expect(screen.getByText('larga').tagName).toBe('EM');

        const image = container.querySelector('img');

        expect(image).toHaveAttribute('alt', '');
        expect(image).toHaveAttribute('loading', 'lazy');
        expect(image).toHaveAttribute('width');
    });

    it('CA-029-16: never runs a script from the description nor keeps a javascript link', () => {
        const { container } = renderActivity({ activity: { ...activity, descriptionHtml: '<p>Hola</p><script>window.hacked = true</script><a href="javascript:alert(1)">clic</a>' } });

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('a[href^="javascript:"]')).toBeNull();
    });

    it('says the speakers are to be confirmed when none is visible and leaves out the empty description section', () => {
        renderActivity({ activity: { ...activity, speakers: [], descriptionHtml: null, imageUrl: null, categories: [] } });

        expect(screen.getByText('Ponentes por confirmar.')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 2, name: 'Descripción' })).not.toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Categorías de la actividad' })).not.toBeInTheDocument();
    });

    it('CA-029-18: sets the title with the activity, the event and the organization and uses the summary as description', () => {
        renderActivity();

        expect(document.head.querySelector('title')).toHaveTextContent('Taller de huertos · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[name="description"]')).toHaveAttribute('content', 'Aprende a plantar.');
        expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
    });

    it('links back to the program and the speakers and keeps the language switcher', () => {
        renderActivity();

        expect(screen.getByRole('link', { name: 'Volver al programa' })).toHaveAttribute('href', '/parques/events/congreso/program');
        expect(screen.getByRole('navigation', { name: 'Idioma' })).toBeInTheDocument();
    });

    describe('seats, place and external details (plan 035)', () => {
        const park = { name: 'Parque Lineal', isExternal: true, address: 'Entrada norte, junto al quiosco', mapUrl: 'https://maps.example.test/parque', capacity: null };

        it('CA-035-25: shows the quota as a number of people with no registration button or text', () => {
            const { container } = renderActivity({ activity: { ...activity, limitedSeats: true, seats: 30 } });

            expect(screen.getByText('Cupo')).toBeInTheDocument();
            expect(screen.getByText('30 personas')).toBeInTheDocument();
            expect(screen.queryByRole('button')).not.toBeInTheDocument();
            expect(container).not.toHaveTextContent(/inscri/i);
            expect(container).not.toHaveTextContent(/disponibles/i);
        });

        it('CA-035-25: uses the singular for one person and shows nothing about quota when there is none', () => {
            const { unmount } = renderActivity({ activity: { ...activity, limitedSeats: true, seats: 1 } });

            expect(screen.getByText('1 persona')).toBeInTheDocument();
            unmount();

            renderActivity();

            expect(screen.queryByText('Cupo')).not.toBeInTheDocument();
            expect(screen.queryByRole('heading', { level: 2, name: 'Lugar y participación' })).not.toBeInTheDocument();
        });

        it('CA-035-26: shows the place with its address and a map link that opens safely', () => {
            renderActivity({ activity: { ...activity, place: park } });

            expect(screen.getByRole('heading', { level: 2, name: 'Lugar y participación' })).toBeInTheDocument();
            expect(screen.getByText('Parque Lineal')).toBeInTheDocument();
            expect(screen.getByText('Entrada norte, junto al quiosco')).toBeInTheDocument();

            const map = screen.getByRole('link', { name: 'Ver mapa de Parque Lineal (se abre en una pestaña nueva)' });

            expect(map).toHaveAttribute('href', 'https://maps.example.test/parque');
            expect(map).toHaveAttribute('rel', 'noopener noreferrer');
            expect(map).toHaveAttribute('target', '_blank');
        });

        it('CA-035-26: leaves out the address, the map and the capacity when there is no data', () => {
            renderActivity({ activity: { ...activity, place: { name: 'Sala B', isExternal: false, address: null, mapUrl: null, capacity: null } } });

            expect(screen.getByText('Sala B')).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /Ver mapa/ })).not.toBeInTheDocument();
            expect(screen.queryByText('Capacidad del lugar')).not.toBeInTheDocument();
        });

        it('CA-035-26: shows the capacity of the place only when it comes in the props', () => {
            renderActivity({ activity: { ...activity, place: { ...park, capacity: 120 } } });

            expect(screen.getByText('Capacidad del lugar')).toBeInTheDocument();
            expect(screen.getByText('120 personas')).toBeInTheDocument();
        });

        it('CA-035-30: never renders a map link that is not https even if the server sent it', () => {
            const { container } = renderActivity({ activity: { ...activity, place: { ...park, mapUrl: 'javascript:alert(1)' } } });

            expect(container.querySelector('a[href^="javascript:"]')).toBeNull();
            expect(screen.queryByRole('link', { name: /Ver mapa/ })).not.toBeInTheDocument();
        });

        it('CA-035-27: shows the meeting point and the transport when they exist and skips the empty ones', () => {
            const { unmount } = renderActivity({ activity: { ...activity, place: park, meetingPoint: 'Puerta norte', transport: 'Sale del hotel a las 8:00' } });

            expect(screen.getByText('Punto de reunión')).toBeInTheDocument();
            expect(screen.getByText('Puerta norte')).toBeInTheDocument();
            expect(screen.getByText('Transporte')).toBeInTheDocument();
            expect(screen.getByText('Sale del hotel a las 8:00')).toBeInTheDocument();
            unmount();

            renderActivity({ activity: { ...activity, place: park } });

            expect(screen.queryByText('Punto de reunión')).not.toBeInTheDocument();
            expect(screen.queryByText('Transporte')).not.toBeInTheDocument();
        });

        it('CA-035-28: lists the public contacts with phone and email links and only the data they have', () => {
            renderActivity({
                activity: {
                    ...activity,
                    place: park,
                    contacts: [
                        { name: 'Ana Pública', phone: '55 1111 2222', email: 'ana@example.test' },
                        { name: 'Luis Solo', phone: null, email: null },
                    ],
                },
            });

            expect(screen.getByRole('heading', { level: 2, name: 'Responsables' })).toBeInTheDocument();
            expect(screen.getByText('Ana Pública')).toBeInTheDocument();
            expect(screen.getByRole('link', { name: '55 1111 2222' })).toHaveAttribute('href', 'tel:5511112222');
            expect(screen.getByRole('link', { name: 'ana@example.test' })).toHaveAttribute('href', 'mailto:ana@example.test');
            expect(screen.getByText('Luis Solo')).toBeInTheDocument();
            expect(screen.getAllByRole('link').filter((link) => link.getAttribute('href')?.startsWith('tel:'))).toHaveLength(1);
        });

        it('CA-035-28: has no contacts section when the list is empty', () => {
            renderActivity({ activity: { ...activity, place: park, contacts: [] } });

            expect(screen.queryByRole('heading', { level: 2, name: 'Responsables' })).not.toBeInTheDocument();
        });

        it('CA-035-30: escapes texts that look like markup instead of rendering them', () => {
            const { container } = renderActivity({
                activity: {
                    ...activity,
                    place: { ...park, name: '<b>Parque</b>', address: '<img src=x onerror=alert(1)>' },
                    meetingPoint: '<script>window.hacked = true</script>',
                    transport: '<a href="javascript:alert(2)">bus</a>',
                },
            });

            expect(screen.getByText('<b>Parque</b>')).toBeInTheDocument();
            expect(container.querySelector('b')).toBeNull();
            expect(container.querySelector('script')).toBeNull();
            expect(container.querySelector('img[onerror]')).toBeNull();
            expect(container.querySelector('a[href^="javascript:"]')).toBeNull();
        });

        it('CA-035-31: keeps the headings in order h1, then the h2 sections including the new ones', () => {
            renderActivity({ activity: { ...activity, place: park, seats: 30, limitedSeats: true, contacts: [{ name: 'Ana', phone: null, email: null }] } });

            expect(screen.getAllByRole('heading', { level: 2 }).map((heading) => heading.textContent)).toEqual(['Lugar y participación', 'Responsables', 'Descripción', 'Ponentes']);
        });
    });
});
