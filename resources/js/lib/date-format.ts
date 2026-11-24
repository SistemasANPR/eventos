import type { TimeFormat } from '@/types/event';

/**
 * Opciones de la hora según el formato del evento (plan 023). Con 24 h se usa `hourCycle: 'h23'` y
 * no `hour12: false`, que en algunos idiomas escribe la medianoche como «24:00».
 */
function hourOptions(timeFormat: TimeFormat): Intl.DateTimeFormatOptions {
    return timeFormat === '12h' ? { hour: 'numeric', hour12: true } : { hour: '2-digit', hourCycle: 'h23' };
}

/**
 * Fechas y horas de un evento en SU zona horaria y en el idioma de la página,
 * siempre con `Intl` (resources/js/CLAUDE.md §6). El servidor entrega instantes
 * en UTC (ISO 8601); la zona IANA del evento solo decide cómo se muestran.
 */
export function formatEventDates(startsAt: string, endsAt: string, timeZone: string, locale: string, timeFormat: TimeFormat): string {
    const formatter = new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        ...hourOptions(timeFormat),
        minute: '2-digit',
        timeZone,
        timeZoneName: 'short',
    });

    return formatter.formatRange(new Date(startsAt), new Date(endsAt));
}

/** Un instante en la zona horaria del evento y en el idioma de la página (fechas de la convocatoria). */
export function formatEventInstant(instant: string, timeZone: string, locale: string, timeFormat: TimeFormat): string {
    return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        ...hourOptions(timeFormat),
        minute: '2-digit',
        timeZone,
        timeZoneName: 'short',
    }).format(new Date(instant));
}

/**
 * Hora local de una actividad (`HH:MM`, sin zona) en el formato del evento (plan 029, DA-5). Los valores
 * ya son la hora del evento: se construye un instante «de reloj» en UTC y se formatea con `timeZone: 'UTC'`,
 * así la zona del navegador del visitante no los desplaza.
 */
export function formatLocalTime(hhmm: string, locale: string, timeFormat: TimeFormat): string {
    const [hours = 0, minutes = 0] = hhmm.split(':').map(Number);

    return new Intl.DateTimeFormat(locale, { ...hourOptions(timeFormat), minute: '2-digit', timeZone: 'UTC' }).format(
        new Date(Date.UTC(2000, 0, 1, hours, minutes)),
    );
}

/**
 * Día local de una actividad (`AAAA-MM-DD`, sin zona) en el idioma de la página, fijado en UTC por el mismo
 * motivo que `formatLocalTime`. `long` para encabezados; `short` para la navegación entre días.
 */
export function formatLocalDate(date: string, locale: string, style: 'long' | 'short' = 'long'): string {
    const options: Intl.DateTimeFormatOptions =
        style === 'long' ? { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' } : { weekday: 'short', month: 'short', day: 'numeric' };

    return new Intl.DateTimeFormat(locale, { ...options, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** Zona del evento con su desfase de UTC en el instante dado («America/Mexico_City (GMT-6)»), para mostrarla una sola vez. */
export function formatTimeZoneOffset(timeZone: string, locale: string, instant: string): string {
    const offset = new Intl.DateTimeFormat(locale, { timeZone, timeZoneName: 'shortOffset' })
        .formatToParts(new Date(instant))
        .find((part) => part.type === 'timeZoneName')?.value;

    return offset === undefined ? timeZone : `${timeZone} (${offset})`;
}

/**
 * Instante de publicación de un documento, en UTC y en el idioma de la página.
 * Se usa UTC porque la zona horaria del evento no se conoce en esas páginas.
 */
export function formatPublishedAt(publishedAt: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: 'UTC',
        timeZoneName: 'short',
    }).format(new Date(publishedAt));
}
