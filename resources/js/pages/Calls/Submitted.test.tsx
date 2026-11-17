import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';

import Submitted from '@/pages/Calls/Submitted';
import { createTranslate } from '@/lib/translate';

const translations = {
    layout: { skip_to_content: 'Saltar al contenido', footer: 'Plataforma' },
    submitted: {
        title: 'Recibimos tu propuesta',
        body: 'Tu propuesta para «:call» quedó registrada.',
        back_to_call: 'Volver a la convocatoria',
        back_to_event: 'Volver al evento',
    },
};

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate(translations), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
}));

describe('Calls/Submitted page', () => {
    it('CA-006-57: confirms the submission with the call title and no personal data', () => {
        render(
            <Submitted
                call={{ title: 'Convocatoria de ponencias' }}
                event={{ name: 'Congreso' }}
                organization={{ name: 'Parques', slug: 'parques' }}
                callUrl="/parques/events/congreso/call/ponencias?lang=es"
                eventUrl="/parques/events/congreso?lang=es"
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Recibimos tu propuesta' })).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Tu propuesta para «Convocatoria de ponencias» quedó registrada.');
        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
    });
});
