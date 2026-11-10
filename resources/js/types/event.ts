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

/** Evento en la página del aviso: coincide con las props de `Events/Privacy` (solo lo que esa página muestra). */
export interface PrivacyPageEvent {
    name: string;
    /** Idioma de ESTA página. */
    locale: string;
    /** Idiomas del evento. */
    locales: string[];
}

/** Aviso de privacidad publicado, con el cuerpo ya sanitizado en el servidor. */
export interface PrivacyNotice {
    version: number;
    /** Instante de publicación en UTC (ISO 8601). */
    publishedAt: string;
    /** HTML ya sanitizado: solo p, br, strong, em, ul, ol, li y a con href http o https. */
    body: string;
    /** Idioma real del texto; puede ser el idioma base si falta el pedido. */
    locale: string;
}
