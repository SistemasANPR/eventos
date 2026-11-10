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
