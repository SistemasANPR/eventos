import { buildLandingContent, type LandingInput } from '@/lib/call-landing-content';
import type { PublicCall } from '@/types/call';

const call: PublicCall = {
    title: 'Convocatoria de ponencias',
    description: 'Envía tu propuesta',
    submissionType: 'Sesión',
    state: 'open',
    opensAt: '2027-01-10T15:00:00Z',
    closesAt: '2027-02-15T23:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '24h',
    locale: 'es',
    locales: ['es'],
    tracks: [
        { name: 'Ciencia', description: 'Investigación' },
        { name: 'Gestión', description: null },
    ],
};

const input: LandingInput = {
    call,
    event: { name: 'Congreso Parques', logoUrl: '/logo.png' },
    slugs: { organization: 'parques', event: 'congreso', call: 'ponencias' },
    data: {
        minParticipants: 1,
        maxParticipants: 3,
        formProposal: [{ name: 'Resumen', isRequired: true }],
        formParticipant: [{ name: 'Nombre(s)', isRequired: true }],
        otherCalls: [{ slug: 'posters', title: 'Pósters', description: null, url: '/parques/events/congreso/call/posters?lang=es' }],
    },
};

describe('buildLandingContent', () => {
    it('builds everything from the data of the call, with generic text and nothing invented', () => {
        const content = buildLandingContent(input);

        expect(content.title).toBe('Convocatoria de ponencias');
        expect(content.eventLine).toBe('Congreso Parques');
        expect(content.lede).toBe('Envía tu propuesta');
        expect(content.axes.items.map((axis) => axis.name)).toEqual(['Ciencia', 'Gestión']);
        expect(content.requirements.items[0]).toContain('3 personas');
        expect(content.requirements.aside).toMatchObject({ kind: 'form' });
        expect(content.cover).toMatchObject({ url: '/logo.png', isLogo: true });
        expect(content.venue).toBeUndefined();
        expect(content.resultsLabel).toBeUndefined();
        expect(content.faqs.map((faq) => faq.question)).toEqual([
            '¿Cuándo cierra la convocatoria?',
            '¿Cuántas personas pueden participar en una propuesta?',
        ]);
    });

    it('announces the other calls of the event with a generic button and no cover', () => {
        const [promo] = buildLandingContent(input).promos;

        expect(promo).toMatchObject({ url: '/parques/events/congreso/call/posters?lang=es', title: 'Pósters', button: 'Ver convocatoria' });
        expect(promo?.coverUrl).toBeUndefined();
    });

    it('leaves out what the call does not have: no axes, no form, no logo, an individual proposal', () => {
        const content = buildLandingContent({
            ...input,
            call: { ...call, tracks: [] },
            event: { name: 'Congreso Parques' },
            data: { ...input.data, maxParticipants: 1, formProposal: [], formParticipant: [], otherCalls: [] },
        });

        expect(content.axes.items).toEqual([]);
        expect(content.requirements.aside).toBeUndefined();
        expect(content.requirements.items).toEqual(['La propuesta es **individual**.']);
        expect(content.cover).toBeUndefined();
        expect(content.promos).toEqual([]);
    });

    it('the own text of a call replaces the automatic one, and only for the fields it writes', () => {
        const content = buildLandingContent(input, {
            title: 'Titular propio',
            cover: { url: '/portada.webp', alt: 'Portada' },
            promos: { posters: { title: 'Anuncio propio', coverUrl: '/otra.webp' } },
        });

        expect(content.title).toBe('Titular propio');
        expect(content.cover).toEqual({ url: '/portada.webp', alt: 'Portada' });
        expect(content.promos[0]).toMatchObject({ title: 'Anuncio propio', coverUrl: '/otra.webp', button: 'Ver convocatoria' });
        expect(content.axes.items).toHaveLength(2);
    });

    it('the two ANPR calls keep their own text and covers', () => {
        const slugs = { organization: 'convocatorias', event: 'congreso-parques-2027', call: 'registro-de-poster-cientifico' };
        const otherCalls = [{ slug: 'registro-de-sesiones-educativas', title: 'Sesiones', description: null, url: '/s' }];
        const content = buildLandingContent({ ...input, slugs, data: { ...input.data, otherCalls } });

        expect(content.lede).toContain('proyecto académico');
        expect(content.cover?.url).toContain('portada-posters-cientificos');
        expect(content.promos[0]).toMatchObject({ title: '¿Te gustaría ser ponente en el Congreso?', button: 'Postular sesión' });
    });
});
