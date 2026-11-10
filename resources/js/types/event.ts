/** Evento público: coincide con `App\Support\Events\PublicEventData` (sin identificadores internos). */
export interface PublicEvent {
    name: string;
    description: string | null;
    /** Instante de inicio en UTC (ISO 8601). */
    startsAt: string;
    /** Instante de fin en UTC (ISO 8601). */
    endsAt: string;
    /** Zona horaria IANA del evento, solo para mostrar las fechas. */
    timezone: string;
    venueName: string | null;
    venueAddress: string | null;
    city: string | null;
    /** Idioma de esta respuesta. */
    locale: string;
    /** Idiomas en que el evento está disponible. */
    locales: string[];
}

export interface PublicOrganization {
    name: string;
    slug: string;
}
