import { screen, waitFor } from '@testing-library/react';

import { mountLandingEditor } from '@/landing-editor';
import type { LandingEditorApi, LandingEditorProps } from '@/types/landing';

vi.mock('../css/landing-editor.css', () => ({}));

const props: LandingEditorProps = {
    blocks: [{ id: 's1', type: 'spacer', size: 'md' }],
    version: 1,
    locales: ['es'],
    defaultLocale: 'es',
    translations: {},
    preview: {
        event: {
            name: 'Congreso',
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
    },
    publicUrl: 'https://example.test/p/events/e',
    hasUnpublishedChanges: false,
};

const api: LandingEditorApi = {
    saveDraft: vi.fn(),
    publish: vi.fn(),
    discard: vi.fn(),
    uploadImage: vi.fn(),
};

function host(data: string): HTMLElement {
    const element = document.createElement('div');
    element.dataset.props = data;
    document.body.appendChild(element);

    return element;
}

describe('mountLandingEditor', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    it('CA-022-35: mounts the editor from data-props and exposes window.mountLandingEditor', async () => {
        const element = host(JSON.stringify(props));

        expect(window.mountLandingEditor).toBe(mountLandingEditor);
        mountLandingEditor(element, api);

        await waitFor(() => expect(screen.getByTestId('canvas-block-0')).toBeInTheDocument());
        expect(element.dataset.mounted).toBe('true');
    });

    it('CA-022-35: does not mount twice on the same element', async () => {
        const element = host(JSON.stringify(props));

        mountLandingEditor(element, api);
        await waitFor(() => expect(screen.getAllByTestId('canvas-block-0')).toHaveLength(1));
        mountLandingEditor(element, api);

        expect(screen.getAllByTestId('canvas-block-0')).toHaveLength(1);
    });

    it('CA-022-35: leaves the fault visible instead of faking an editor when props or api are missing', () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const broken = host('not json');

        mountLandingEditor(broken, api);
        mountLandingEditor(host(JSON.stringify(props)), undefined);

        expect(error).toHaveBeenCalledTimes(2);
        expect(broken.dataset.mounted).toBeUndefined();
    });
});
