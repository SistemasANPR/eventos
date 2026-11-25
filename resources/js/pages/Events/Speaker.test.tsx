import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import Speaker from '@/pages/Events/Speaker';
import { createTranslate } from '@/lib/translate';
import type { PublicEvent } from '@/types/event';
import type { ProgramNav, PublicActivitySummary, PublicSpeaker } from '@/types/program';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    event: { language: 'Idioma', language_option: 'Ver en :language', privacy_link: 'Aviso de privacidad' },
    languages: { es: 'Español', en: 'English' },
    program: { until: 'Termina el :date', time_range: ':start – :end', speakers: 'Ponentes' },
    speakers: { role_separator: ', ', program_link: 'Ver el programa' },
    speaker: {
        description: ':name en :event.',
        bio: 'Biografía',
        links: 'Enlaces',
        categories: 'Categorías del ponente',
        activities: 'Actividades',
        no_activities: 'Sin actividades programadas por ahora.',
        back_to_speakers: 'Volver a los ponentes',
        networks: { linkedin: 'LinkedIn', website: 'Sitio web' },
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

const speaker: PublicSpeaker = {
    id: 's1',
    name: 'Ana Torres',
    company: 'Parques de México',
    jobTitle: 'Directora',
    tagline: 'Cuida espacios verdes.',
    photoUrl: '/parques/events/congreso/speakers/s1/photo',
    url: '/parques/events/congreso/speakers/s1',
    bioHtml: '<p>Una <strong>larga</strong> trayectoria.</p>',
    categories: ['Invitados'],
    socialLinks: [
        { network: 'linkedin', url: 'https://example.test/ana' },
        { network: 'mastodon', url: 'https://example.test/otra' },
    ],
};

const activity: PublicActivitySummary = {
    id: 'a1',
    title: 'Taller de huertos',
    summary: null,
    date: '2026-11-20',
    endDate: '2026-11-20',
    startTime: '09:00',
    endTime: '10:00',
    categories: [],
    speakers: [],
    iconUrl: null,
    url: '/parques/events/congreso/activities/a1',
};

function renderSpeaker(props: Partial<Parameters<typeof Speaker>[0]> = {}) {
    return render(<Speaker event={event} organization={organization} speaker={speaker} activities={[activity]} nav={nav} {...props} />);
}

describe('Events/Speaker', () => {
    it('CA-029-9: shows the name as h1, the role, the tagline, the categories and the biography', () => {
        renderSpeaker();

        expect(screen.getByRole('heading', { level: 1, name: 'Ana Torres' })).toBeInTheDocument();
        expect(screen.getByText('Directora, Parques de México')).toBeInTheDocument();
        expect(screen.getAllByText('Cuida espacios verdes.').length).toBeGreaterThan(0);
        expect(within(screen.getByRole('list', { name: 'Categorías del ponente' })).getByText('Invitados')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Biografía' })).toBeInTheDocument();
        expect(screen.getByText('larga').tagName).toBe('STRONG');
    });

    it('CA-029-16: paints the biography through RichText: no script runs and no javascript link survives', () => {
        const { container } = renderSpeaker({
            speaker: { ...speaker, bioHtml: '<p>Hola</p><script>window.hacked = true</script><a href="javascript:alert(1)">clic</a>' },
        });

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('a[href^="javascript:"]')).toBeNull();
        expect(screen.queryByText(/window\.hacked/)).not.toBeInTheDocument();
        expect(screen.getByText('clic')).toBeInTheDocument();
    });

    it('CA-029-16: external links carry rel noopener noreferrer nofollow and use the translated network name', () => {
        renderSpeaker();

        const linkedin = screen.getByRole('link', { name: 'LinkedIn' });

        expect(linkedin).toHaveAttribute('href', 'https://example.test/ana');
        expect(linkedin).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        // Una red que la interfaz no conoce se muestra con su nombre.
        expect(screen.getByRole('link', { name: 'mastodon' })).toHaveAttribute('rel', 'noopener noreferrer nofollow');
    });

    it('CA-029-22: the photo has the name of the speaker as alternative text, is lazy and keeps its size', () => {
        const { container } = renderSpeaker();
        const photo = container.querySelector('img[alt="Ana Torres"]');

        expect(photo).not.toBeNull();
        expect(photo).toHaveAttribute('loading', 'lazy');
        expect(photo).toHaveAttribute('width');
        expect(photo).toHaveAttribute('height');
    });

    it('CA-029-9: lists the activities of the speaker under an h2 with level 3 cards', () => {
        renderSpeaker();

        expect(screen.getByRole('heading', { level: 2, name: 'Actividades' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'Taller de huertos' })).toBeInTheDocument();
        expect(screen.queryByText('Sin actividades programadas por ahora.')).not.toBeInTheDocument();
    });

    it('CA-029-11: a speaker without visible activities shows the message instead of an empty list', () => {
        renderSpeaker({ activities: [] });

        expect(screen.getByText('Sin actividades programadas por ahora.')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 3 })).not.toBeInTheDocument();
    });

    it('leaves out the optional sections when the speaker does not have them', () => {
        const { container } = renderSpeaker({ speaker: { ...speaker, bioHtml: null, categories: [], socialLinks: [], photoUrl: null, company: null, jobTitle: null, tagline: null } });

        expect(screen.queryByRole('heading', { level: 2, name: 'Biografía' })).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { level: 2, name: 'Enlaces' })).not.toBeInTheDocument();
        expect(screen.queryByRole('list', { name: 'Categorías del ponente' })).not.toBeInTheDocument();
        expect(container.querySelector('img')).toBeNull();
    });

    it('CA-029-18: sets the title with the speaker, the event and the organization and uses the tagline as description', () => {
        renderSpeaker();

        expect(document.head.querySelector('title')).toHaveTextContent('Ana Torres · Congreso Parques · Parques de México');
        expect(document.head.querySelector('meta[name="description"]')).toHaveAttribute('content', 'Cuida espacios verdes.');
        expect(document.head.querySelector('meta[name="robots"]')).toBeNull();
    });

    it('links back to the speakers and the program and keeps the language switcher', () => {
        renderSpeaker();

        expect(screen.getByRole('link', { name: 'Volver a los ponentes' })).toHaveAttribute('href', '/parques/events/congreso/speakers');
        expect(screen.getByRole('link', { name: 'Ver el programa' })).toHaveAttribute('href', '/parques/events/congreso/program');
        expect(screen.getByRole('navigation', { name: 'Idioma' })).toBeInTheDocument();
    });
});
