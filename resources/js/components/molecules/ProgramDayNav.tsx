import { useTranslation } from '@/hooks/useTranslation';
import { FOCUS_RING } from '@/lib/classes';
import { formatLocalDate } from '@/lib/date-format';
import { cn } from '@/lib/utils';

interface ProgramDayNavProps {
    /** Días con actividades, `AAAA-MM-DD`, en orden. */
    days: string[];
    /** Día que se está viendo. */
    currentDay: string | null;
    /** Dirección del programa (puede traer `?lang=`); a ella se añade `day=`. */
    baseUrl: string;
    /** Idioma de la página. */
    locale: string;
}

/**
 * Enlaces a los días del programa: cada día tiene su propia dirección (`?day=`), así se comparte y se
 * indexa. El día que se ve lleva `aria-current`. Con un solo día no hay nada que elegir y no se dibuja.
 * Usar sobre la lista de actividades de un día; no para otras pestañas ni filtros.
 */
export function ProgramDayNav({ days, currentDay, baseUrl, locale }: ProgramDayNavProps) {
    const { t } = useTranslation();

    if (days.length < 2) {
        return null;
    }

    return (
        <nav aria-label={t('program.days_label')}>
            <ul className="flex flex-wrap gap-2">
                {days.map((day) => {
                    const isCurrent = day === currentDay;

                    return (
                        <li key={day}>
                            <a
                                href={dayUrl(baseUrl, day)}
                                aria-current={isCurrent ? 'page' : undefined}
                                className={cn(
                                    'inline-flex min-h-11 items-center rounded-full border-2 border-primary px-4 text-base font-semibold',
                                    FOCUS_RING,
                                    isCurrent ? 'bg-primary text-primary-contrast' : 'bg-surface text-primary hover:bg-bg',
                                )}
                            >
                                {formatLocalDate(day, locale, 'short')}
                            </a>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

function dayUrl(baseUrl: string, day: string): string {
    return `${baseUrl}${baseUrl.includes('?') ? '&' : '?'}day=${day}`;
}
