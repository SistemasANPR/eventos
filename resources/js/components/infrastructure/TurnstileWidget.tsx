import { useEffect, useEffectEvent, useRef } from 'react';

interface TurnstileApi {
    render: (
        container: HTMLElement,
        options: {
            sitekey: string;
            language: string;
            callback: (token: string) => void;
            'expired-callback': () => void;
            'error-callback': () => void;
        },
    ) => string;
    reset: (widgetId: string) => void;
    remove: (widgetId: string) => void;
}

declare global {
    interface Window {
        turnstile?: TurnstileApi;
    }
}

interface TurnstileWidgetProps {
    /** Llave PÚBLICA del sitio (la secreta nunca llega al navegador). */
    siteKey: string;
    locale: string;
    /** Recibe el token vigente, o `null` cuando caduca o falla. */
    onToken: (token: string | null) => void;
    /** El script de Cloudflare no cargó o el widget falló. */
    onLoadError: () => void;
    /** Cada vez que cambia, el widget pide un token nuevo (un token vale para un solo envío). */
    resetCount: number;
}

const SCRIPT_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

let scriptPromise: Promise<void> | null = null;

/**
 * Widget de Cloudflare Turnstile (plan 006, H-006-2). Carga el script oficial solo cuando se monta,
 * es decir, solo en el formulario de envío. Sin aspecto propio: el iframe lo pinta Cloudflare.
 */
export function TurnstileWidget({ siteKey, locale, onToken, onLoadError, resetCount }: TurnstileWidgetProps) {
    const container = useRef<HTMLDivElement>(null);
    const widgetId = useRef<string | null>(null);
    const handleToken = useEffectEvent(onToken);
    const handleError = useEffectEvent(onLoadError);

    useEffect(() => {
        let isCancelled = false;

        loadScript()
            .then(() => {
                const element = container.current;

                if (isCancelled || element === null || window.turnstile === undefined) {
                    return;
                }

                widgetId.current = window.turnstile.render(element, {
                    sitekey: siteKey,
                    language: locale,
                    callback: (token) => handleToken(token),
                    'expired-callback': () => handleToken(null),
                    'error-callback': () => {
                        handleToken(null);
                        handleError();
                    },
                });
            })
            .catch(() => handleError());

        return () => {
            isCancelled = true;

            if (widgetId.current !== null) {
                window.turnstile?.remove(widgetId.current);
                widgetId.current = null;
            }
        };
    }, [siteKey, locale]);

    useEffect(() => {
        if (resetCount > 0 && widgetId.current !== null) {
            window.turnstile?.reset(widgetId.current);
        }
    }, [resetCount]);

    return <div ref={container} data-testid="turnstile-widget" />;
}

function loadScript(): Promise<void> {
    if (window.turnstile !== undefined) {
        return Promise.resolve();
    }

    scriptPromise ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_SRC;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            // Permite reintentar si la red falló la primera vez.
            scriptPromise = null;
            script.remove();
            reject(new Error('turnstile-script'));
        };
        document.head.appendChild(script);
    });

    return scriptPromise;
}
