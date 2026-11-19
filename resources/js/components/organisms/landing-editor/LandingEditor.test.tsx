import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { LandingEditor, type LandingEditorComponentProps } from '@/components/organisms/landing-editor/LandingEditor';
import type { LandingBlockDraft, LandingEditorApi, LandingPreviewData, LandingSaveResult } from '@/types/landing';

const translations = {
    languages: { es: 'Español', en: 'English' },
    landing_editor: {
        canvas: { block_label: 'Bloque :position de :total: :block', actions: 'Acciones de :block' },
        block: { hero: 'Portada', rich_text: 'Texto', event_info: 'Datos del evento', calls: 'Convocatorias', spacer: 'Espacio' },
        announce: {
            moved: ':block movido a la posición :position de :total',
            duplicated: ':block duplicado en la posición :position de :total',
            removed: ':block quitado, quedan :total',
            added: ':block añadido en la posición :position de :total',
        },
        missing_text: 'Falta el texto en :language',
        settings: { block_title: 'Ajustes: :block' },
    },
};

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
    tracks: [],
};

function hero(id: string, title: string): LandingBlockDraft {
    return {
        id,
        type: 'hero',
        title: { es: title, en: '' },
        subtitle: { es: '', en: '' },
        image: null,
        imageAlt: { es: '', en: '' },
        buttonLabel: { es: '', en: '' },
        buttonUrl: '',
        tone: 'light',
        align: 'start',
    };
}

function spacer(id: string): LandingBlockDraft {
    return { id, type: 'spacer', size: 'md' };
}

function text(id: string, html: string): LandingBlockDraft {
    return { id, type: 'rich_text', html: { es: html, en: '' }, width: 'wide' };
}

/**
 * Usuario simulado con `fireEvent` (el proyecto no incluye `user-event`): clic, escritura, selección,
 * subida de archivos y teclado sobre el elemento con el foco.
 */
function simulatedUser() {
    const asInput = (element: HTMLElement): HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement => {
        if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement || element instanceof HTMLSelectElement) {
            return element;
        }

        throw new Error('El elemento no es un campo');
    };

    return {
        click: (element: HTMLElement): Promise<void> => {
            fireEvent.click(element);

            return Promise.resolve();
        },
        clear: (element: HTMLElement): Promise<void> => {
            fireEvent.change(element, { target: { value: '' } });

            return Promise.resolve();
        },
        type: (element: HTMLElement, text: string): Promise<void> => {
            fireEvent.change(element, { target: { value: asInput(element).value + text } });

            return Promise.resolve();
        },
        selectOptions: (element: HTMLElement, value: string): Promise<void> => {
            fireEvent.change(element, { target: { value } });

            return Promise.resolve();
        },
        upload: (element: HTMLElement, file: File): Promise<void> => {
            fireEvent.change(element, { target: { files: [file] } });

            return Promise.resolve();
        },
        keyboard: (sequence: string): Promise<void> => {
            let altKey = false;

            for (const [, closing, name, holding, character] of sequence.matchAll(/\{(\/?)(\w+)(>?)\}|(.)/g)) {
                if (name === 'Alt') {
                    altKey = closing === '' && holding === '>';
                    continue;
                }

                const key = name ?? character ?? '';
                fireEvent.keyDown(document.activeElement ?? document.body, { key, altKey });
            }

            return Promise.resolve();
        },
    };
}

function makeApi(overrides: Partial<LandingEditorApi> = {}): LandingEditorApi {
    return {
        saveDraft: vi.fn((blocks, version) => Promise.resolve({ ok: true as const, version: version + 1, blocks })),
        publish: vi.fn((blocks, version) => Promise.resolve({ ok: true as const, version: version + 1, blocks })),
        discard: vi.fn(() => Promise.resolve({ ok: true as const, version: 9, blocks: [hero('h1', 'Publicado')] })),
        uploadImage: vi.fn(() => Promise.resolve({ ok: true as const, image: { id: 'img-1', url: '/editor/img-1' } })),
        ...overrides,
    };
}

function setup(blocks: LandingBlockDraft[], api: LandingEditorApi = makeApi(), extra: Partial<LandingEditorComponentProps> = {}) {
    const user = simulatedUser();
    const utils = render(
        <LandingEditor
            blocks={blocks}
            version={3}
            locales={['es', 'en']}
            defaultLocale="es"
            translations={translations}
            preview={preview}
            publicUrl="https://example.test/p/events/e"
            hasUnpublishedChanges={false}
            api={api}
            {...extra}
        />,
    );

    return { user, api, ...utils };
}

const block = (position: number): HTMLElement => screen.getByTestId(`canvas-block-${position}`);

describe('LandingEditor', () => {
    it('CA-022-35: draws the draft on the canvas with the public renderer and a view toggle', async () => {
        const { user } = setup([hero('h1', 'Bienvenidos'), spacer('s1')]);

        expect(screen.getByRole('heading', { level: 1, name: 'Bienvenidos' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /landing_editor.action.view/ })).toHaveAttribute('href', 'https://example.test/p/events/e');

        const mobile = screen.getByRole('button', { name: 'landing_editor.viewport.mobile' });
        await user.click(mobile);

        expect(mobile).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'landing_editor.viewport.desktop' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('CA-022-36: selects a block with a click and shows its form in the settings panel', async () => {
        const { user } = setup([hero('h1', 'Bienvenidos'), spacer('s1')]);

        expect(screen.getByText('landing_editor.settings.empty')).toBeInTheDocument();

        await user.click(block(0));

        expect(block(0)).toHaveAttribute('aria-current', 'true');
        expect(block(1)).not.toHaveAttribute('aria-current');
        expect(screen.getByRole('heading', { name: 'Ajustes: Portada' })).toBeInTheDocument();
        expect(screen.getByLabelText('landing_editor.field.title')).toHaveValue('Bienvenidos');
    });

    it('CA-022-36: selects a block with Enter when it has the focus', async () => {
        const { user } = setup([hero('h1', 'Bienvenidos'), spacer('s1')]);

        block(1).focus();
        await user.keyboard('{Enter}');

        expect(block(1)).toHaveAttribute('aria-current', 'true');
        expect(screen.getByRole('heading', { name: 'Ajustes: Espacio' })).toBeInTheDocument();
    });

    it('CA-022-36: a change in the form shows on the canvas before saving', async () => {
        const { user } = setup([hero('h1', 'Bienvenidos')]);

        await user.click(block(0));
        const field = screen.getByLabelText('landing_editor.field.title');
        await user.clear(field);
        await user.type(field, 'Nuevo título');

        expect(screen.getByRole('heading', { level: 1, name: 'Nuevo título' })).toBeInTheDocument();
        expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.dirty');
    });

    it('CA-022-36: the block text is not activable inside the canvas (inert) and selection comes from the outline', () => {
        setup([text('t1', '<p>Hola <a href="https://example.test">enlace</a></p>')]);

        expect(block(0).querySelector('[inert]')).not.toBeNull();
    });

    it('CA-022-35: switching the language shows the base-language text as fallback and flags the missing one', async () => {
        const { user } = setup([hero('h1', 'Bienvenidos')]);

        await user.click(block(0));
        await user.selectOptions(screen.getByLabelText('landing_editor.canvas.language'), 'en');

        // El idioma inglés está vacío: el lienzo cae al español y el campo avisa que falta.
        expect(screen.getByRole('heading', { level: 1, name: 'Bienvenidos' })).toBeInTheDocument();
        expect(screen.getByLabelText('landing_editor.field.title')).toHaveValue('');
        expect(screen.getAllByTestId('missing-text')[0]).toHaveTextContent('Falta el texto en English');
        expect(screen.getByRole('tab', { name: /English/ })).toHaveAttribute('aria-selected', 'true');
    });

    it('CA-022-37: moves a block with the buttons, keeps the focus on it and announces the position', async () => {
        const { user } = setup([hero('h1', 'Uno'), spacer('s1'), text('t1', '<p>Tres</p>')]);

        await user.click(block(0));
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.move_down' }));

        expect(block(1)).toHaveAttribute('aria-current', 'true');
        await waitFor(() => expect(block(1)).toHaveFocus());
        expect(screen.getByTestId('announcer')).toHaveTextContent('Portada movido a la posición 2 de 3');
    });

    it('CA-022-37: moves with Alt+Arrow, duplicates with D and keeps the keyboard flow', async () => {
        const { user } = setup([hero('h1', 'Uno'), spacer('s1')]);

        block(1).focus();
        await user.keyboard('{Alt>}{ArrowUp}{/Alt}');

        expect(block(0)).toHaveAttribute('aria-label', 'Bloque 1 de 2: Espacio');
        await waitFor(() => expect(block(0)).toHaveFocus());

        await user.keyboard('d');

        expect(screen.getAllByRole('button', { name: /^Bloque \d de 3/ })).toHaveLength(3);
        expect(screen.getByTestId('announcer')).toHaveTextContent('Espacio duplicado en la posición 2 de 3');
        await waitFor(() => expect(block(1)).toHaveFocus());
    });

    it('CA-022-37: removing asks for confirmation; cancelling keeps the block and the focus', async () => {
        const { user } = setup([hero('h1', 'Uno'), spacer('s1')]);

        await user.click(block(1));
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.remove' }));

        const dialog = screen.getByRole('alertdialog');
        expect(within(dialog).getByRole('button', { name: 'landing_editor.confirm.cancel' })).toHaveFocus();

        await user.keyboard('{Escape}');

        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(screen.getAllByRole('button', { name: /^Bloque/ })).toHaveLength(2);
        await waitFor(() => expect(block(1)).toHaveFocus());
    });

    it('CA-022-37: confirming removes the block, announces it and moves the focus to its neighbour', async () => {
        const { user } = setup([hero('h1', 'Uno'), spacer('s1')]);

        block(1).focus();
        await user.keyboard('{Delete}');
        await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'landing_editor.confirm.remove_confirm' }));

        expect(screen.getAllByRole('button', { name: /^Bloque/ })).toHaveLength(1);
        expect(screen.getByTestId('announcer')).toHaveTextContent('Espacio quitado, quedan 1');
        await waitFor(() => expect(block(0)).toHaveFocus());
    });

    it('CA-022-38: adds a block from the library after the selected one and selects it', async () => {
        const { user } = setup([hero('h1', 'Uno'), spacer('s1')]);

        await user.click(block(0));
        await user.click(screen.getByRole('button', { name: 'landing_editor.side.library' }));
        const library = screen.getByRole('region', { name: 'landing_editor.library.title' });
        await user.click(within(library).getByRole('button', { name: /Texto/ }));

        expect(screen.getAllByRole('button', { name: /^Bloque/ })).toHaveLength(3);
        expect(block(1)).toHaveAttribute('aria-current', 'true');
        expect(screen.getByRole('heading', { name: 'Ajustes: Texto' })).toBeInTheDocument();
        expect(screen.getByTestId('announcer')).toHaveTextContent('Texto añadido en la posición 2 de 3');
    });

    it('CA-022-10: stops offering blocks at the limit of 30', async () => {
        const full = Array.from({ length: 30 }, (_, index) => spacer(`s${index}`));
        const { user } = setup(full);

        await user.click(screen.getByRole('button', { name: 'landing_editor.side.library' }));

        const library = screen.getByRole('region', { name: 'landing_editor.library.title' });
        const additions = within(library).getAllByRole('button');

        expect(additions).toHaveLength(9);
        additions.forEach((add) => expect(add).toBeDisabled());
    });

    it('CA-022-38: «Guardar borrador» sends the blocks with the version and shows success; «Actualizar» publishes', async () => {
        const { user, api } = setup([hero('h1', 'Uno')]);

        expect(screen.getByRole('button', { name: 'landing_editor.action.save_draft' })).toBeDisabled();

        await user.click(block(0));
        await user.type(screen.getByLabelText('landing_editor.field.subtitle'), 'Sub');
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));

        await waitFor(() => expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.success_save'));
        expect(api.saveDraft).toHaveBeenCalledTimes(1);
        expect(vi.mocked(api.saveDraft).mock.calls[0]?.[1]).toBe(3);

        await user.click(screen.getByRole('button', { name: 'landing_editor.action.publish' }));

        await waitFor(() => expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.success_publish'));
        expect(api.publish).toHaveBeenCalledTimes(1);
        // La versión que devolvió el guardado (4) es la de partida del siguiente guardado.
        expect(vi.mocked(api.publish).mock.calls[0]?.[1]).toBe(4);
    });

    it('CA-022-16: shows the loading state and blocks the buttons while it saves', async () => {
        let finish: (() => void) | undefined;
        const api = makeApi({
            publish: vi.fn<LandingEditorApi['publish']>(
                (blocks, version) =>
                    new Promise<LandingSaveResult>((resolve) => {
                        finish = () => resolve({ ok: true as const, version: version + 1, blocks });
                    }),
            ),
        });
        const { user } = setup([hero('h1', 'Uno')], api, { hasUnpublishedChanges: true });

        await user.click(screen.getByRole('button', { name: 'landing_editor.action.publish' }));

        expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.busy_publish');
        expect(screen.getByRole('button', { name: 'landing_editor.action.publish' })).toBeDisabled();

        finish?.();
        await waitFor(() => expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.success_publish'));
    });

    it('CA-022-18: discarding asks for confirmation and then loads the published version', async () => {
        const { user, api } = setup([hero('h1', 'Borrador')], makeApi(), { hasUnpublishedChanges: true });

        await user.click(screen.getByRole('button', { name: 'landing_editor.action.discard' }));
        expect(api.discard).not.toHaveBeenCalled();

        await user.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'landing_editor.confirm.discard_confirm' }));

        await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Publicado' })).toBeInTheDocument());
        expect(api.discard).toHaveBeenCalledTimes(1);
    });

    it('CA-022-15: on a version conflict it warns and lets the organizer reload the newest version', async () => {
        const api = makeApi({
            saveDraft: vi.fn(() =>
                Promise.resolve({ ok: false as const, reason: 'conflict' as const, version: 7, blocks: [hero('h1', 'De otra persona')] }),
            ),
        });
        const { user } = setup([hero('h1', 'Mío')], api);

        await user.click(block(0));
        await user.type(screen.getByLabelText('landing_editor.field.subtitle'), 'x');
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));

        expect(await screen.findByText('landing_editor.conflict.title')).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 1, name: 'Mío' })).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'landing_editor.conflict.reload' }));

        expect(screen.getByRole('heading', { level: 1, name: 'De otra persona' })).toBeInTheDocument();
        expect(screen.queryByText('landing_editor.conflict.title')).toBeNull();
    });

    it('CA-022-15: keeping my changes after a conflict saves with the server version', async () => {
        const saveDraft = vi
            .fn<LandingEditorApi['saveDraft']>()
            .mockResolvedValueOnce({ ok: false, reason: 'conflict', version: 7, blocks: [hero('h1', 'Otra')] })
            .mockImplementation((blocks, version) => Promise.resolve({ ok: true, version: version + 1, blocks }));
        const { user } = setup([hero('h1', 'Mío')], makeApi({ saveDraft }));

        await user.click(block(0));
        await user.type(screen.getByLabelText('landing_editor.field.subtitle'), 'x');
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));
        await user.click(await screen.findByRole('button', { name: 'landing_editor.conflict.keep_mine' }));
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));

        await waitFor(() => expect(saveDraft).toHaveBeenCalledTimes(2));
        expect(saveDraft.mock.calls[1]?.[1]).toBe(7);
    });

    it('CA-022-9: shows the server validation errors next to their field', async () => {
        const api = makeApi({
            saveDraft: vi.fn(() =>
                Promise.resolve({
                    ok: false as const,
                    reason: 'invalid' as const,
                    errors: { 'blocks.0.title.es': ['El título es obligatorio.'] },
                }),
            ),
        });
        const { user } = setup([hero('h1', 'Mío')], api);

        await user.click(block(0));
        await user.type(screen.getByLabelText('landing_editor.field.subtitle'), 'x');
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));

        expect(await screen.findByText('El título es obligatorio.')).toBeInTheDocument();
        expect(screen.getByLabelText('landing_editor.field.title')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByTestId('editor-status')).toHaveTextContent('landing_editor.status.invalid');
    });

    it('CA-022-20: a failed request shows the error and keeps the work in the editor', async () => {
        const api = makeApi({ saveDraft: vi.fn(() => Promise.reject(new Error('red'))) });
        const { user } = setup([hero('h1', 'Mío')], api);

        await user.click(block(0));
        await user.type(screen.getByLabelText('landing_editor.field.subtitle'), 'x');
        await user.click(screen.getByRole('button', { name: 'landing_editor.action.save_draft' }));

        expect(await screen.findByText('landing_editor.status.error')).toBeInTheDocument();
        expect(screen.getByLabelText('landing_editor.field.subtitle')).toHaveValue('x');
        expect(screen.getByRole('button', { name: 'landing_editor.action.save_draft' })).toBeEnabled();
    });

    it('CA-022-31: uploads the hero image through the api and shows it on the canvas', async () => {
        const { user, api } = setup([hero('h1', 'Uno')]);

        await user.click(block(0));
        await user.upload(screen.getByLabelText('landing_editor.field.image'), new File(['x'], 'portada.png', { type: 'image/png' }));

        await waitFor(() => expect(block(0).querySelector('img')).toHaveAttribute('src', '/editor/img-1'));
        expect(api.uploadImage).toHaveBeenCalledTimes(1);
        // La imagen exige texto alternativo: el campo aparece y avisa que falta.
        expect(screen.getByLabelText('landing_editor.field.image_alt')).toBeInTheDocument();
    });

    it('CA-022-9: shows the upload errors the server returns', async () => {
        const api = makeApi({
            uploadImage: vi.fn(() => Promise.resolve({ ok: false as const, errors: ['El archivo pesa más de 5 MB.'] })),
        });
        const { user } = setup([hero('h1', 'Uno')], api);

        await user.click(block(0));
        await user.upload(screen.getByLabelText('landing_editor.field.image'), new File(['x'], 'a.png', { type: 'image/png' }));

        expect(await screen.findByText('El archivo pesa más de 5 MB.')).toBeInTheDocument();
    });

    it('CA-022-39: controls have names, the tab list follows the languages and the live region exists', () => {
        setup([hero('h1', 'Uno')]);

        expect(screen.getByRole('toolbar', { name: 'landing_editor.toolbar' })).toBeInTheDocument();
        expect(screen.getByTestId('announcer')).toHaveAttribute('aria-live', 'polite');
    });
});
