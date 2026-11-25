import { useTranslation } from '@/hooks/useTranslation';
import { CARD_TITLE_LINK, CATEGORY_CHIP, FOCUS_RING, HEADING_MD, LIST_CARD } from '@/lib/classes';
import { formatLocalDate, formatLocalTime } from '@/lib/date-format';
import { cn } from '@/lib/utils';
import type { TimeFormat } from '@/types/event';
import type { PublicActivitySummary } from '@/types/program';

interface ActivityCardProps {
    activity: PublicActivitySummary;
    /** Idioma de la página. */
    locale: string;
    /** Cómo se muestran las horas del evento (12 h o 24 h). */
    timeFormat: TimeFormat;
    className?: string;
}

/**
 * Tarjeta de una actividad del programa público: horario local del evento, título (enlace al detalle),
 * resumen, categorías y ponentes. Una actividad que cruza la medianoche muestra su fecha de fin.
 * Usar en la lista de actividades de un día; no para ponentes ni para el detalle completo.
 */
export function ActivityCard({ activity, locale, timeFormat, className }: ActivityCardProps) {
    const { t } = useTranslation();

    const range = t('program.time_range', {
        start: formatLocalTime(activity.startTime, locale, timeFormat),
        end: formatLocalTime(activity.endTime, locale, timeFormat),
    });
    const endsAnotherDay = activity.endDate !== activity.date;

    return (
        <article className={cn(LIST_CARD, className)}>
            <div className="flex items-start gap-4">
                {activity.iconUrl !== null && <img src={activity.iconUrl} alt="" width={48} height={48} loading="lazy" className="h-12 w-12 shrink-0 rounded-lg object-cover" />}
                <div className="flex min-w-0 flex-col gap-1">
                    <p className="text-sm font-bold text-support">{range}</p>
                    {endsAnotherDay && <p className="text-sm text-text-muted">{t('program.until', { date: formatLocalDate(activity.endDate, locale) })}</p>}
                    <h3 className={HEADING_MD}>
                        <a href={activity.url} className={cn(CARD_TITLE_LINK, FOCUS_RING)}>
                            {activity.title}
                        </a>
                    </h3>
                </div>
            </div>

            {activity.summary !== null && <p className="text-base leading-relaxed text-text">{activity.summary}</p>}

            {activity.categories.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {activity.categories.map((category) => (
                        <li key={category.id} className={CATEGORY_CHIP}>
                            {category.name}
                        </li>
                    ))}
                </ul>
            )}

            {activity.speakers.length > 0 && (
                <ul aria-label={t('program.speakers')} className="flex flex-col gap-1 text-sm text-text-muted">
                    {activity.speakers.map((speaker) => (
                        <li key={speaker.id}>
                            <span className="font-semibold text-text">{speaker.name}</span>
                            {speaker.company !== null && <span>{`, ${speaker.company}`}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}
