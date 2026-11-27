import { fireEvent, render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';

import { BlockLibrary } from '@/components/organisms/landing-editor/BlockLibrary';
import { CallLandingPage } from '@/components/organisms/CallLandingPage';
import { LandingEditor } from '@/components/organisms/landing-editor/LandingEditor';
import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import { blockTypesFor, createBlock } from '@/lib/landing-blocks';
import { countMissingTexts, resolveDraftBlock } from '@/lib/landing-draft';
import { createTranslate, type Translate } from '@/lib/translate';
import type { PublicCall } from '@/types/call';
import type { LandingBlockDraft, LandingEditorApi, LandingPreviewData, PublicLandingBlock } from '@/types/landing';

vi.mock('@/hooks/useTranslation', () => ({
    useTranslation: () => ({ t: createTranslate({ languages: { es: 'Español', en: 'English' } }), locale: 'es' }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title?: string }): ReactNode => <title>{title}</title>,
}));

const t: Translate = (key) => key;

const call: PublicCall = {
    title: 'Convocatoria de ponencias',
    description: 'Envía tu propuesta',
    submissionType: 'Sesión',
    state: 'open',
    opensAt: '2026-11-24T06:00:00Z',
    closesAt: '2027-01-30T23:00:00Z',
    resultsAt: '2027-03-15T18:00:00Z',
    timezone: 'America/Mexico_City',
    timeFormat: '24h',
    locale: 'es',
    locales: ['es', 'en'],
    tracks: [],
};

const context = { call, submitUrl: '/parques/events/congreso/call/ponencias/submit?lang=es', logoUrl: '/logo.png' };

const sections: PublicLandingBlock[] = [
    {
        id: 'h',
        type: 'call_hero',
        title: 'Título propio',
        eventLine: 'Congreso',
        lede: 'Texto',
        notice: 'Sin membresía',
        imageUrl: null,
        imageAlt: null,
    },
    {
        id: 'd',
        type: 'call_dates',
        timezoneLabel: 'CDMX',
        resultsLabel: 'Marzo de 2027',
        venue: { name: 'Parque Central', note: 'Mazatlán' },
    },
    { id: 'a', type: 'call_axes', title: 'Ejes', intro: 'Elige uno', axes: [{ name: 'Ciencia', description: 'Investigación' }] },
    {
        id: 'r',
        type: 'call_requirements',
        title: 'Requisitos',
        intro: null,
        items: ['Participa **solo**'],
        buttonLabel: 'Abrir el formulario',
        form: {
            title: 'Lo que pide',
            requiredNote: '* obligatorio',
            groups: [{ title: 'Propuesta', fields: [{ name: 'Resumen', isRequired: true }] }],
        },
    },
    { id: 's', type: 'call_steps', title: 'Pasos', steps: [{ icon: 'doc', title: 'Prepara', text: 'Reúne todo' }] },
    { id: 'f', type: 'faq', title: null, items: [{ question: '¿Dónde?', answerHtml: '<p>Aquí</p>' }] },
    {
        id: 'p',
        type: 'call_promos',
        promos: [{ url: '/otra', title: 'Pósters', text: 'Comparte tu trabajo', button: 'Ver convocatoria', coverUrl: null }],
    },
];

describe('LandingRenderer with a call (plan 033)', () => {
    it('CA-033-15: draws every section with the design of the call landing, reading dates and state from the call', () => {
        const { container } = render(<LandingRenderer blocks={sections} locale="es" t={t} call={context} />);

        expect(container.querySelector('.cl')).not.toBeNull();
        expect(screen.getByRole('heading', { level: 1, name: 'Título propio' })).toBeInTheDocument();
        expect(screen.getByText('24 de noviembre de 2026')).toBeInTheDocument();
        expect(screen.getByText('Marzo de 2027')).toBeInTheDocument();
        expect(screen.getByText('Parque Central')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 3, name: 'Ciencia' })).toBeInTheDocument();
        expect(screen.getByText('Resumen')).toBeInTheDocument();
        expect(screen.getByText('solo').tagName).toBe('STRONG');
        expect(screen.getByText('Prepara')).toBeInTheDocument();
        expect(screen.getByText('¿Dónde?')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Pósters/ })).toHaveAttribute('href', '/otra');
        screen
            .getAllByRole('link', { name: 'Enviar propuesta' })
            .forEach((link) => expect(link).toHaveAttribute('href', context.submitUrl));
    });

    it('CA-033-15: uses the logo of the event as the cover when the section has no image, and its own image when it has one', () => {
        const { unmount } = render(<LandingRenderer blocks={sections.slice(0, 1)} locale="es" t={t} call={context} />);

        expect(screen.getByRole('img')).toHaveAttribute('src', '/logo.png');
        unmount();

        const withImage: PublicLandingBlock = {
            ...(sections[0] as PublicLandingBlock),
            imageUrl: '/portada.webp',
            imageAlt: 'Portada',
        } as PublicLandingBlock;
        render(<LandingRenderer blocks={[withImage]} locale="es" t={t} call={context} />);

        expect(screen.getByRole('img', { name: 'Portada' })).toHaveAttribute('src', '/portada.webp');
    });

    it('CA-033-15: locks the button when the call does not receive proposals', () => {
        render(
            <LandingRenderer
                blocks={sections.slice(0, 1)}
                locale="es"
                t={t}
                call={{ call: { ...call, state: 'closed' }, submitUrl: null, logoUrl: null }}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Enviar propuesta' })).not.toBeInTheDocument();
        screen.getAllByText('Convocatoria cerrada').forEach((button) => {
            expect(button).toHaveAttribute('aria-disabled', 'true');
            expect(button).not.toHaveAttribute('href');
        });
    });

    it('without a call, the sections of a call are not drawn', () => {
        const { container } = render(<LandingRenderer blocks={sections} locale="es" t={t} />);

        expect(container.querySelector('.cl')).toBeNull();
        expect(screen.queryByText('Título propio')).not.toBeInTheDocument();
    });

    it('draws the blocks of the event inside the call landing, centered', () => {
        const { container } = render(
            <LandingRenderer
                blocks={[{ id: 'x', type: 'rich_text', html: '<p>Hola</p>', width: 'wide' }]}
                locale="es"
                t={t}
                call={context}
            />,
        );

        expect(screen.getByText('Hola')).toBeInTheDocument();
        expect(container.querySelector('.cl-libre')).not.toBeNull();
    });
});

describe('CallLandingPage', () => {
    it('wraps the blocks with the bar, the footer and the links of the call', () => {
        render(
            <CallLandingPage
                call={{ ...call, extraLinks: [{ label: 'Bases', url: 'https://example.org/bases' }] }}
                event={{ name: 'Congreso', logoUrl: null }}
                organization={{ name: 'Parques', slug: 'parques' }}
                blocks={sections}
                eventUrl="/parques/events/congreso?lang=es"
                privacyUrl="/parques/events/congreso/privacy"
                submitUrl={context.submitUrl}
            />,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Título propio' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Volver al evento' })).toHaveAttribute('href', '/parques/events/congreso?lang=es');
        expect(screen.getByRole('link', { name: 'Bases' })).toHaveAttribute('rel', 'noopener noreferrer nofollow');
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute('href', '?lang=en');
    });
});

const preview: LandingPreviewData = {
    event: {
        name: 'Congreso Parques',
        description: null,
        startsAt: '2027-03-10T15:00:00Z',
        endsAt: '2027-03-12T23:00:00Z',
        timezone: 'America/Mexico_City',
        timeFormat: '12h',
        venueName: null,
        city: null,
    },
    calls: [],
    tracks: [{ name: 'Eje uno', description: null }],
    call: {
        call,
        logoUrl: null,
        liveItems: ['Participa **en equipo**'],
        form: null,
        promos: [{ url: '/otra', title: 'Pósters', text: 'Texto', button: 'Ver convocatoria', coverUrl: null }],
    },
};

const defaults = (key: string): string => `relleno:${key}`;

describe('call blocks in the draft (plan 033)', () => {
    it('every call section can be created empty and has valid starting values', () => {
        const created = blockTypesFor('call')
            .filter((type) => type.startsWith('call_'))
            .map((type) => createBlock(type, ['es', 'en']));

        expect(created.map((block) => block.type)).toEqual([
            'call_hero',
            'call_dates',
            'call_axes',
            'call_requirements',
            'call_steps',
            'call_promos',
        ]);
    });

    it('CA-033-14: the library of the event does not offer the sections of a call; the one of a call offers them first', () => {
        expect(blockTypesFor('event')).not.toContain('call_hero');
        expect(blockTypesFor('call').slice(0, 2)).toEqual(['call_hero', 'call_dates']);
        expect(blockTypesFor('call')).toContain('hero');
    });

    it('an empty text falls back to the data of the call or the template text, a written one wins', () => {
        const hero = createBlock('call_hero', ['es', 'en']);
        const axes = createBlock('call_axes', ['es', 'en']);

        expect(resolveDraftBlock(hero, 'es', 'es', preview, defaults)).toMatchObject({
            title: 'Convocatoria de ponencias',
            eventLine: 'Congreso Parques',
            lede: 'Envía tu propuesta',
        });
        expect(resolveDraftBlock(axes, 'es', 'es', preview, defaults)).toMatchObject({ title: 'relleno:axes_title', axes: preview.tracks });
        expect(
            resolveDraftBlock({ ...hero, title: { es: 'Mío', en: '' } } as LandingBlockDraft, 'es', 'es', preview, defaults),
        ).toMatchObject({
            title: 'Mío',
        });
    });

    it('requirements add the live lines before the custom ones and skip the empty custom lines', () => {
        const base = createBlock('call_requirements', ['es', 'en']);
        const block = {
            ...base,
            items: [
                { key: 'k1', text: { es: 'Uno', en: '' } },
                { key: 'k2', text: { es: '', en: '' } },
            ],
        } as LandingBlockDraft;

        expect(resolveDraftBlock(block, 'es', 'es', preview, defaults)).toMatchObject({ items: ['Participa **en equipo**', 'Uno'] });
    });

    it('counts the required texts that are missing in a language (steps need title and text, a venue-less date none)', () => {
        const steps = createBlock('call_steps', ['es', 'en']);
        const dates = createBlock('call_dates', ['es', 'en']);

        expect(countMissingTexts(steps, 'es')).toBe(2);
        expect(countMissingTexts(dates, 'es')).toBe(0);
    });
});

describe('BlockLibrary and template (plan 033)', () => {
    it('CA-033-14: lists the call sections in the call editor and offers the template only when it is available', () => {
        const { unmount } = render(<BlockLibrary kind="event" canAdd onAdd={vi.fn()} t={t} />);

        expect(screen.queryByText('landing_editor.block.call_hero')).not.toBeInTheDocument();
        expect(screen.queryByText('landing_editor.library.template')).not.toBeInTheDocument();
        unmount();

        const onApplyTemplate = vi.fn();
        render(<BlockLibrary kind="call" canAdd onAdd={vi.fn()} onApplyTemplate={onApplyTemplate} t={t} />);

        expect(screen.getByText('landing_editor.block.call_hero')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'landing_editor.library.template' }));
        expect(onApplyTemplate).toHaveBeenCalledOnce();
    });
});

describe('LandingEditor of a call (plan 033)', () => {
    const api: LandingEditorApi = {
        saveDraft: vi.fn(),
        publish: vi.fn(),
        discard: vi.fn(),
        uploadImage: vi.fn(),
    };

    function editor(blocks: LandingBlockDraft[], template: LandingBlockDraft[]) {
        return render(
            <LandingEditor
                blocks={blocks}
                version={1}
                locales={['es', 'en']}
                defaultLocale="es"
                translations={{
                    languages: { es: 'Español' },
                    landing_editor: {
                        side: { label: 'Panel', switch: 'Cambiar', settings: 'Ajustes', library: 'Biblioteca' },
                        confirm: {
                            template_title: 'Usar plantilla',
                            template_body: 'Se reemplaza',
                            template_confirm: 'Sí, usar',
                            cancel: 'Cancelar',
                        },
                        library: { title: 'Añadir', hint: 'Elige', template: 'Usar la plantilla' },
                        block: { call_hero: 'Portada de la convocatoria', call_steps: 'Pasos', spacer: 'Espacio' },
                    },
                }}
                preview={preview}
                publicUrl={null}
                hasUnpublishedChanges={false}
                kind="call"
                template={template}
                api={api}
            />,
        );
    }

    it('CA-033-13: applying the template replaces the blocks after confirming, and cancelling keeps them', () => {
        const own = createBlock('spacer', ['es', 'en']);
        const fromTemplate = [createBlock('call_hero', ['es', 'en']), createBlock('call_steps', ['es', 'en'])];
        editor([own], fromTemplate);

        fireEvent.click(screen.getByRole('button', { name: 'Biblioteca' }));
        fireEvent.click(screen.getByRole('button', { name: 'Usar la plantilla' }));
        const bar = screen.getByText('Usar plantilla').closest('div') as HTMLElement;
        fireEvent.click(within(bar.parentElement ?? bar).getByRole('button', { name: 'Cancelar' }));

        expect(screen.getAllByTestId(/canvas-block-/)).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Usar la plantilla' }));
        fireEvent.click(screen.getByRole('button', { name: 'Sí, usar' }));

        expect(screen.getAllByTestId(/canvas-block-/)).toHaveLength(2);
    });
});
