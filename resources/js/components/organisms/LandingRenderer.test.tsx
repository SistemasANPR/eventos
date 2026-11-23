import { render, screen, within } from '@testing-library/react';

import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import type { Translate } from '@/lib/translate';
import type { PublicLandingBlock, PublicLandingCall } from '@/types/landing';

/** Traductor de prueba: devuelve la clave (y los valores de sustitución) para comprobar qué texto se pide. */
const t: Translate = (key, replacements) => (replacements === undefined ? key : `${key} ${Object.values(replacements).join(' ')}`);

function call(state: PublicLandingCall['state'], registerUrl: string | null): PublicLandingCall {
    return {
        title: `Convocatoria ${state}`,
        submissionType: 'Sesión',
        state,
        opensAt: '2027-01-10T15:00:00Z',
        closesAt: '2027-02-15T23:00:00Z',
        timezone: 'America/Mexico_City',
        timeFormat: '12h',
        registerUrl,
    };
}

const hero: PublicLandingBlock = {
    id: 'b1',
    type: 'hero',
    title: 'Congreso Parques',
    subtitle: 'Tres días',
    imageUrl: '/img/portada.jpg',
    imageAlt: 'Parque al amanecer',
    button: { label: 'Más información', url: 'https://example.test/info' },
    tone: 'light',
    align: 'start',
};

function renderBlocks(blocks: PublicLandingBlock[]) {
    return render(<LandingRenderer blocks={blocks} locale="es" t={t} />);
}

describe('LandingRenderer', () => {
    it('CA-022-21: draws the blocks in the saved order', () => {
        renderBlocks([
            { id: 'a', type: 'rich_text', html: '<p>Primero</p>', width: 'wide' },
            { id: 'b', type: 'rich_text', html: '<p>Segundo</p>', width: 'wide' },
            { id: 'c', type: 'rich_text', html: '<p>Tercero</p>', width: 'wide' },
        ]);

        const order = screen.getAllByText(/Primero|Segundo|Tercero/).map((node) => node.textContent);

        expect(order).toEqual(['Primero', 'Segundo', 'Tercero']);
    });

    it('CA-022-40: the hero shows its title as the only h1, its image with alt and a safe button', () => {
        renderBlocks([
            hero,
            {
                id: 'b2',
                type: 'event_info',
                name: 'Evento',
                description: null,
                startsAt: '2027-03-10T15:00:00Z',
                endsAt: '2027-03-12T23:00:00Z',
                timezone: 'America/Mexico_City',
                timeFormat: '12h',
                venueName: null,
                city: null,
            },
        ]);

        expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 1, name: 'Congreso Parques' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'Evento' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Parque al amanecer' })).toHaveAttribute('src', '/img/portada.jpg');
        expect(screen.getByRole('link', { name: 'Más información' })).toHaveAttribute('rel', 'noopener noreferrer nofollow');
    });

    it('CA-022-40: without a hero the event name carries the h1', () => {
        renderBlocks([
            {
                id: 'b2',
                type: 'event_info',
                name: 'Evento',
                description: 'Descripción',
                startsAt: '2027-03-10T15:00:00Z',
                endsAt: '2027-03-12T23:00:00Z',
                timezone: 'America/Mexico_City',
                timeFormat: '24h',
                venueName: 'Sede',
                city: 'CDMX',
            },
        ]);

        expect(screen.getByRole('heading', { level: 1, name: 'Evento' })).toBeInTheDocument();
        expect(screen.getByText('Sede, CDMX')).toBeInTheDocument();
        expect(screen.getByText(/marzo de 2027/)).toBeInTheDocument();
    });

    it('hides the time zone, the call category and the tracks on the public site (organizer decision)', () => {
        renderBlocks([
            {
                id: 'i',
                type: 'event_info',
                name: 'Evento',
                description: null,
                startsAt: '2027-03-10T15:00:00Z',
                endsAt: '2027-03-12T23:00:00Z',
                timezone: 'America/Mexico_City',
                timeFormat: '12h',
                venueName: 'Sede',
                city: 'CDMX',
            },
            { id: 'c', type: 'calls', title: null, calls: [call('open', '/enviar')] },
            {
                id: 't',
                type: 'tracks',
                title: 'Temas',
                tracks: [{ name: 'Ciencia', description: null }],
            },
        ]);

        expect(screen.queryByText('America/Mexico_City')).not.toBeInTheDocument();
        expect(screen.queryByText('event.timezone')).not.toBeInTheDocument();
        expect(screen.queryByText('call.type')).not.toBeInTheDocument();
        expect(screen.queryByText('Sesión')).not.toBeInTheDocument();
        expect(screen.queryByText('Ciencia')).not.toBeInTheDocument();
        // Lo demás sigue visible: sede, fechas y las fechas de la convocatoria.
        expect(screen.getByText('Sede, CDMX')).toBeInTheDocument();
        expect(screen.getByText('call.opens_at')).toBeInTheDocument();
    });

    it('CA-022-25: an open call links its register button to the submission form', () => {
        renderBlocks([
            {
                id: 'c',
                type: 'calls',
                title: null,
                calls: [call('open', '/p/events/e/call/c/submit')],
            },
        ]);

        expect(screen.getByRole('link', { name: 'submit.send_action' })).toHaveAttribute('href', '/p/events/e/call/c/submit');
    });

    it.each(['scheduled', 'paused', 'closed'] as const)(
        'CA-022-26: a %s call shows a blocked register button: disabled, no link, state visible and announced',
        (state) => {
            renderBlocks([{ id: 'c', type: 'calls', title: null, calls: [call(state, null)] }]);

            const item = screen.getByRole('listitem');
            const button = within(item).getByRole('button');

            expect(button).toBeDisabled();
            expect(button).toHaveAttribute('aria-disabled', 'true');
            expect(within(item).queryByRole('link')).toBeNull();
            expect(within(item).getByTestId('call-state')).toHaveTextContent(`call.state.${state}`);
            expect(button).toHaveTextContent(`landing.register_unavailable call.state.${state}`);
        },
    );

    it('CA-022-24: shows the empty message when the event has no published calls', () => {
        renderBlocks([{ id: 'c', type: 'calls', title: null, calls: [] }]);

        expect(screen.getByRole('heading', { level: 2, name: 'landing.calls_title' })).toBeInTheDocument();
        expect(screen.getByText('landing.calls_empty')).toBeInTheDocument();
    });

    it('CA-022-40: faq, gallery, columns and spacer render with their headings and alt texts, and the tracks stay hidden', () => {
        const { container } = renderBlocks([
            {
                id: 't',
                type: 'tracks',
                title: 'Nuestros ejes',
                tracks: [{ name: 'Ciencia', description: 'Investigación' }],
            },
            {
                id: 'f',
                type: 'faq',
                title: null,
                items: [{ question: '¿Dónde?', answerHtml: '<p>En el parque</p>' }],
            },
            {
                id: 'g',
                type: 'image_gallery',
                title: null,
                images: [{ url: '/i/1.jpg', alt: 'Foto uno' }],
            },
            {
                id: 'k',
                type: 'columns',
                columns: [
                    { kind: 'text', html: '<p>Izquierda</p>' },
                    { kind: 'image', url: '/i/2.jpg', alt: 'Foto dos' },
                ],
            },
            { id: 's', type: 'spacer', size: 'lg' },
        ]);

        expect(screen.queryByRole('heading', { level: 2, name: 'Nuestros ejes' })).not.toBeInTheDocument();
        expect(screen.queryByText('Ciencia')).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'landing.faq_title' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: '¿Dónde?' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Foto uno' })).toBeInTheDocument();
        expect(screen.getByRole('img', { name: 'Foto dos' })).toBeInTheDocument();
        expect(screen.getByText('Izquierda')).toBeInTheDocument();
        expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
    });

    it('CA-022-11: rich text never injects scripts into the page', () => {
        const { container } = renderBlocks([
            {
                id: 'r',
                type: 'rich_text',
                html: '<p>Hola</p><script>window.pwned = 1</script><img src=x onerror="window.pwned=1">',
                width: 'wide',
            },
        ]);

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('Hola')).toBeInTheDocument();
    });

    it('CA-022-36: wrapBlock lets the editor wrap each block with its position', () => {
        render(
            <LandingRenderer
                blocks={[
                    { id: 'a', type: 'spacer', size: 'sm' },
                    { id: 'b', type: 'spacer', size: 'md' },
                ]}
                locale="es"
                t={t}
                wrapBlock={(block, index, content) => (
                    <div data-testid={`wrap-${index}`} data-block={block.id}>
                        {content}
                    </div>
                )}
            />,
        );

        expect(screen.getByTestId('wrap-1')).toHaveAttribute('data-block', 'b');
    });
});
