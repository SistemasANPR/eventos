import { render, screen } from '@testing-library/react';

import { ActivityCard } from '@/components/molecules/ActivityCard';
import { createTranslate } from '@/lib/translate';
import type { PublicActivitySummary } from '@/types/program';

const translations = {
    program: { until: 'Termina el :date', time_range: ':start – :end', speakers: 'Ponentes', limited_seats: 'Cupo limitado' },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

const activity: PublicActivitySummary = {
    id: 'a1',
    title: 'Taller de jardinería',
    summary: 'Aprende a plantar.',
    date: '2026-11-20',
    endDate: '2026-11-20',
    startTime: '23:30',
    endTime: '23:59',
    categories: [{ id: 'c1', name: 'Talleres' }],
    speakers: [
        { id: 's1', name: 'Ana Torres', company: 'Parques', jobTitle: null, tagline: null, photoUrl: null, url: '/p/events/c/speakers/s1' },
        { id: 's2', name: 'Luis Pérez', company: null, jobTitle: null, tagline: null, photoUrl: null, url: '/p/events/c/speakers/s2' },
    ],
    iconUrl: null,
    limitedSeats: false,
    url: '/p/events/c/activities/a1',
};

describe('ActivityCard', () => {
    it('CA-029-22: shows the title as a level 3 heading that links to the activity detail', () => {
        render(<ActivityCard activity={activity} locale="es" timeFormat="24h" />);

        const heading = screen.getByRole('heading', { level: 3, name: 'Taller de jardinería' });

        expect(heading).toContainElement(screen.getByRole('link', { name: 'Taller de jardinería' }));
        expect(screen.getByRole('link', { name: 'Taller de jardinería' })).toHaveAttribute('href', '/p/events/c/activities/a1');
    });

    it('CA-029-12: shows the local time of the event in 24 hours without shifting it', () => {
        render(<ActivityCard activity={activity} locale="es" timeFormat="24h" />);

        expect(screen.getByText('23:30 – 23:59')).toBeInTheDocument();
    });

    it('CA-029-12: shows the local time of the event in 12 hours with its day period', () => {
        render(<ActivityCard activity={activity} locale="es" timeFormat="12h" />);

        expect(screen.getByText(/^11:30\s?p\.?\s?m\.? – 11:59\s?p\.?\s?m\.?$/i)).toBeInTheDocument();
    });

    it('CA-029-13: shows the end date when the activity ends on another day', () => {
        render(
            <ActivityCard
                activity={{ ...activity, date: '2026-11-20', endDate: '2026-11-21', startTime: '22:00', endTime: '02:00' }}
                locale="es"
                timeFormat="24h"
            />,
        );

        expect(screen.getByText('22:00 – 02:00')).toBeInTheDocument();
        expect(screen.getByText(/Termina el .*21 de noviembre/)).toBeInTheDocument();
    });

    it('CA-029-13: does not repeat a date when the activity ends the same day', () => {
        render(<ActivityCard activity={activity} locale="es" timeFormat="24h" />);

        expect(screen.queryByText(/Termina el/)).not.toBeInTheDocument();
    });

    it('shows the summary, the categories and the names of the speakers', () => {
        render(<ActivityCard activity={activity} locale="es" timeFormat="24h" />);

        expect(screen.getByText('Aprende a plantar.')).toBeInTheDocument();
        expect(screen.getByText('Talleres')).toBeInTheDocument();
        expect(screen.getByText('Ana Torres')).toBeInTheDocument();
        expect(screen.getByText('Luis Pérez')).toBeInTheDocument();
    });

    it('omits the optional parts when the activity does not have them', () => {
        render(<ActivityCard activity={{ ...activity, summary: null, categories: [], speakers: [] }} locale="es" timeFormat="24h" />);

        expect(screen.queryByText('Ponentes')).not.toBeInTheDocument();
        expect(screen.queryByText('Aprende a plantar.')).not.toBeInTheDocument();
    });

    it('CA-029-22: the decorative icon has an empty alternative text, is lazy and keeps its size', () => {
        const { container } = render(<ActivityCard activity={{ ...activity, iconUrl: '/p/events/c/activities/a1/icon' }} locale="es" timeFormat="24h" />);
        const icon = container.querySelector('img');

        expect(icon).not.toBeNull();
        expect(icon).toHaveAttribute('alt', '');
        expect(icon).toHaveAttribute('loading', 'lazy');
        expect(icon).toHaveAttribute('width');
        expect(icon).toHaveAttribute('height');
    });

    it('CA-035-24: shows the limited quota badge only when the activity has a quota and never a number', () => {
        const { unmount } = render(<ActivityCard activity={{ ...activity, limitedSeats: true }} locale="es" timeFormat="24h" />);

        expect(screen.getByText('Cupo limitado')).toBeInTheDocument();
        expect(screen.getByText('Cupo limitado').closest('article')).not.toHaveTextContent(/\d+\s*personas/);
        unmount();

        render(<ActivityCard activity={activity} locale="es" timeFormat="24h" />);

        expect(screen.queryByText('Cupo limitado')).not.toBeInTheDocument();
    });

    it('CA-035-24: keeps the badge outside the title link so the link name stays the title', () => {
        render(<ActivityCard activity={{ ...activity, limitedSeats: true }} locale="es" timeFormat="24h" />);

        expect(screen.getByRole('link', { name: 'Taller de jardinería' })).toBeInTheDocument();
    });
});
