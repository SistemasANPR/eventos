import { useId } from 'react';

import { FACTS_PANEL, HEADING_MD, LIST_CARD, SECTION_HEADING } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import type { PublicTracksBlock } from '@/types/landing';

interface LandingTracksBlockProps {
    block: PublicTracksBlock;
    t: Translate;
}

/**
 * Si es `true`, el sitio público no muestra los ejes (temas) del evento: decisión del organizador. Siguen
 * existiendo en los datos y en el formulario de envío; para volver a mostrarlos basta ponerlo en `false`.
 */
const HIDE_TRACKS = true;

/** Ejes temáticos activos del evento. Usar en: `LandingRenderer`. */
export function LandingTracksBlock({ block, t }: LandingTracksBlockProps) {
    const headingId = useId();

    if (HIDE_TRACKS) {
        return null;
    }

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4">
            <h2 id={headingId} className={SECTION_HEADING}>
                {block.title ?? t('call.tracks')}
            </h2>
            {block.tracks.length === 0 ? (
                <p className="text-base text-text-muted">{t('landing.tracks_empty')}</p>
            ) : (
                <ul className={FACTS_PANEL}>
                    {block.tracks.map((track, index) => (
                        <li key={`${index}-${track.name}`} className={LIST_CARD}>
                            <h3 className={HEADING_MD}>{track.name}</h3>
                            {track.description !== null && <p className="text-base text-text-muted">{track.description}</p>}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
