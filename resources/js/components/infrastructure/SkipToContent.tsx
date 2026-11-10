import { useTranslation } from '@/hooks/useTranslation';

/**
 * Enlace «Saltar al contenido»: invisible hasta que recibe el foco del teclado.
 * Usar una sola vez, al inicio del layout; apunta al `<main id="contenido">`.
 */
export function SkipToContent() {
    const { t } = useTranslation();

    return (
        <a
            href="#contenido"
            className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-md focus:bg-accent focus:px-4 focus:py-2 focus:text-accent-contrast"
        >
            {t('layout.skip_to_content')}
        </a>
    );
}
