import { useState } from 'react';

import { cn } from '@/lib/utils';

interface EventLogoProps {
    /** Dirección pública del logotipo; `null` o ausente si el evento no tiene. */
    src: string | null | undefined;
    /** Nombre del evento: es el texto alternativo de la imagen. */
    name: string;
    className?: string;
}

/**
 * Logotipo de un evento, con su nombre como texto alternativo. Sin logotipo (o si la imagen no
 * carga) no dibuja nada: ni hueco ni imagen rota.
 * Usar en: la cabecera de las páginas públicas del evento, la convocatoria y la confirmación.
 */
export function EventLogo({ src, name, className }: EventLogoProps) {
    const [hasFailed, setHasFailed] = useState(false);

    if (src === null || src === undefined || src === '' || hasFailed) {
        return null;
    }

    const handleError = (): void => setHasFailed(true);

    return <img src={src} alt={name} className={cn('block h-auto max-h-16 w-auto max-w-full object-contain object-left sm:max-h-20', className)} onError={handleError} />;
}
