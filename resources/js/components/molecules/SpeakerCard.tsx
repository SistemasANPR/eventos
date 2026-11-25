import { useTranslation } from '@/hooks/useTranslation';
import { CARD_TITLE_LINK, FOCUS_RING, HEADING_MD, LIST_CARD } from '@/lib/classes';
import { cn } from '@/lib/utils';
import type { PublicSpeakerSummary } from '@/types/program';

interface SpeakerCardProps {
    speaker: PublicSpeakerSummary;
    className?: string;
}

/**
 * Tarjeta de un ponente del programa público: fotografía (o inicial), nombre (enlace a su ficha), cargo
 * y organización, y su descripción de una línea. Usar en la lista de ponentes y en el detalle de una
 * actividad; no para la ficha completa ni para datos de contacto (nunca se reciben).
 */
export function SpeakerCard({ speaker, className }: SpeakerCardProps) {
    const { t } = useTranslation();
    const role = [speaker.jobTitle, speaker.company].filter((part): part is string => part !== null).join(t('speakers.role_separator'));

    return (
        <article className={cn(LIST_CARD, className)}>
            <div className="flex items-start gap-4">
                {speaker.photoUrl !== null ? (
                    // El nombre está escrito junto a la foto: es decorativa para el lector de pantalla.
                    <img src={speaker.photoUrl} alt="" width={64} height={64} loading="lazy" className="h-16 w-16 shrink-0 rounded-full object-cover" />
                ) : (
                    <span
                        aria-hidden="true"
                        className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-info-bg font-display text-xl font-bold text-info"
                    >
                        {speaker.name.charAt(0).toUpperCase()}
                    </span>
                )}
                <div className="flex min-w-0 flex-col gap-1">
                    <h3 className={HEADING_MD}>
                        <a href={speaker.url} className={cn(CARD_TITLE_LINK, FOCUS_RING)}>
                            {speaker.name}
                        </a>
                    </h3>
                    {role !== '' && <p className="text-sm text-text-muted">{role}</p>}
                </div>
            </div>
            {speaker.tagline !== null && <p className="text-base leading-relaxed text-text">{speaker.tagline}</p>}
        </article>
    );
}
