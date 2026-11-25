import { render, screen } from '@testing-library/react';

import { SpeakerCard } from '@/components/molecules/SpeakerCard';
import { createTranslate } from '@/lib/translate';
import type { PublicSpeakerSummary } from '@/types/program';

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate({ speakers: { role_separator: ', ' } }), locale: 'es' }),
}));

const speaker: PublicSpeakerSummary = {
    id: 's1',
    name: 'Ana Torres',
    company: 'Parques de México',
    jobTitle: 'Directora',
    tagline: 'Cuida espacios verdes.',
    photoUrl: '/p/events/c/speakers/s1/photo',
    url: '/p/events/c/speakers/s1',
};

describe('SpeakerCard', () => {
    it('CA-029-22: shows the name as a level 3 heading that links to the profile', () => {
        render(<SpeakerCard speaker={speaker} />);

        const heading = screen.getByRole('heading', { level: 3, name: 'Ana Torres' });

        expect(heading).toContainElement(screen.getByRole('link', { name: 'Ana Torres' }));
        expect(screen.getByRole('link', { name: 'Ana Torres' })).toHaveAttribute('href', '/p/events/c/speakers/s1');
    });

    it('shows the job title and the organization together, and the tagline', () => {
        render(<SpeakerCard speaker={speaker} />);

        expect(screen.getByText('Directora, Parques de México')).toBeInTheDocument();
        expect(screen.getByText('Cuida espacios verdes.')).toBeInTheDocument();
    });

    it('shows only the part of the role that exists', () => {
        render(<SpeakerCard speaker={{ ...speaker, jobTitle: null }} />);

        expect(screen.getByText('Parques de México')).toBeInTheDocument();
    });

    it('omits the optional parts when the speaker does not have them', () => {
        const { container } = render(<SpeakerCard speaker={{ ...speaker, company: null, jobTitle: null, tagline: null, photoUrl: null }} />);

        expect(screen.queryByText('Cuida espacios verdes.')).not.toBeInTheDocument();
        expect(container.querySelector('img')).toBeNull();
        // Sin fotografía se dibuja la inicial, oculta para el lector de pantalla.
        expect(container.querySelector('[aria-hidden="true"]')).toHaveTextContent('A');
    });

    it('CA-029-22: the photo is lazy, keeps its size and is decorative because the name is next to it', () => {
        const { container } = render(<SpeakerCard speaker={speaker} />);
        const photo = container.querySelector('img');

        expect(photo).not.toBeNull();
        expect(photo).toHaveAttribute('alt', '');
        expect(photo).toHaveAttribute('loading', 'lazy');
        expect(photo).toHaveAttribute('width');
        expect(photo).toHaveAttribute('height');
    });
});
