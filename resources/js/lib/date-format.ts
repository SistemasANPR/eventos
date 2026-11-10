/**
 * Fechas y horas de un evento en SU zona horaria y en el idioma de la página,
 * siempre con `Intl` (resources/js/CLAUDE.md §6). El servidor entrega instantes
 * en UTC (ISO 8601); la zona IANA del evento solo decide cómo se muestran.
 */
export function formatEventDates(startsAt: string, endsAt: string, timeZone: string, locale: string): string {
    const formatter = new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
        timeZoneName: 'short',
    });

    return formatter.formatRange(new Date(startsAt), new Date(endsAt));
}

/** Un instante en la zona horaria del evento y en el idioma de la página (fechas de la convocatoria). */
export function formatEventInstant(instant: string, timeZone: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
        timeZoneName: 'short',
    }).format(new Date(instant));
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
