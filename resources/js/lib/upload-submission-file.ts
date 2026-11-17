/** La subida falló; `message` ya viene en el idioma de la página cuando el servidor lo dio. */
export class UploadFailedError extends Error {}

interface UploadEnvelope {
    success?: boolean;
    data?: { id?: string };
    error?: { message?: string };
}

/**
 * Sube un archivo de un campo de tipo archivo antes del envío (plan 007) y devuelve su identificador
 * de carga pendiente. La ruta responde el sobre JSON del proyecto; el token de carga lo emitió el
 * servidor con el formulario. El token CSRF sale de la cookie `XSRF-TOKEN` de Laravel.
 */
export async function uploadSubmissionFile(url: string, file: File, fieldKey: string, uploadToken: string): Promise<string> {
    const body = new FormData();
    body.append('file', file);
    body.append('form_field_key', fieldKey);
    body.append('upload_token', uploadToken);

    const headers: Record<string, string> = { Accept: 'application/json' };
    const xsrf = readCookie('XSRF-TOKEN');
    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    let response: Response;

    try {
        response = await fetch(url, { method: 'POST', body, headers, credentials: 'same-origin' });
    } catch {
        throw new UploadFailedError('');
    }

    const envelope = (await response.json().catch(() => ({}))) as UploadEnvelope;
    const id = envelope.data?.id;

    if (!response.ok || envelope.success !== true || typeof id !== 'string') {
        throw new UploadFailedError(envelope.error?.message ?? '');
    }

    return id;
}

function readCookie(name: string): string | null {
    const match = document.cookie.split('; ').find((cookie) => cookie.startsWith(`${name}=`));

    return match === undefined ? null : decodeURIComponent(match.slice(name.length + 1));
}
