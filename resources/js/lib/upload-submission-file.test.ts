import { UploadFailedError, uploadSubmissionFile } from '@/lib/upload-submission-file';

const file = new File(['contenido'], 'ponencia.pdf', { type: 'application/pdf' });

afterEach(() => {
    vi.restoreAllMocks();
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

function mockFetch(status: number, body: unknown) {
    return vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

describe('uploadSubmissionFile', () => {
    it('posts the file with the field key, the upload token and the CSRF header, and returns the upload id', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D';
        const fetchMock = mockFetch(201, { success: true, data: { id: 'carga-1' } });

        await expect(uploadSubmissionFile('/p/events/e/call/c/files', file, 'deck', 'token-1')).resolves.toBe('carga-1');

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        const body = init.body as FormData;
        expect(url).toBe('/p/events/e/call/c/files');
        expect(body.get('form_field_key')).toBe('deck');
        expect(body.get('upload_token')).toBe('token-1');
        expect(body.get('file')).toBeInstanceOf(File);
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe('abc=');
    });

    it('fails with the server message when the upload is rejected', async () => {
        mockFetch(403, { success: false, error: { code: 'UPLOAD_TOKEN_INVALID', message: 'Token inválido' } });

        await expect(uploadSubmissionFile('/files', file, 'deck', 'x')).rejects.toThrow('Token inválido');
    });

    it('fails without a message when the network is down', async () => {
        vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('network'));

        await expect(uploadSubmissionFile('/files', file, 'deck', 'x')).rejects.toBeInstanceOf(UploadFailedError);
    });
});
