/*
 * Programa público del evento (plan 029, §4.3): coincide con `App\Support\Program\PublicActivityData`
 * y `PublicSpeakerData`. Lista cerrada de campos: nunca correo, país ni identificadores internos.
 * La fecha y las horas son LOCALES del evento, sin zona: se formatean con `formatLocalDate` y
 * `formatLocalTime`, no se convierten.
 */

export interface PublicCategory {
    id: string;
    name: string;
}

export interface PublicSpeakerSummary {
    id: string;
    name: string;
    company: string | null;
    jobTitle: string | null;
    tagline: string | null;
    photoUrl: string | null;
    url: string;
}

export interface PublicSpeaker extends PublicSpeakerSummary {
    bioHtml: string | null;
    categories: string[];
    socialLinks: { network: string; url: string }[];
}

export interface PublicActivitySummary {
    id: string;
    title: string;
    summary: string | null;
    /** Día de inicio, `AAAA-MM-DD`. */
    date: string;
    /** Día de fin, `AAAA-MM-DD`; distinto de `date` si cruza la medianoche. */
    endDate: string;
    /** `HH:MM` locales del evento. */
    startTime: string;
    endTime: string;
    categories: PublicCategory[];
    speakers: PublicSpeakerSummary[];
    iconUrl: string | null;
    /** La actividad tiene cupo (el programa solo avisa «Cupo limitado»; el número va en el detalle). */
    limitedSeats: boolean;
    url: string;
}

/** El lugar de una actividad, como lo ve el público (plan 035, DA-13). */
export interface PublicPlace {
    name: string;
    isExternal: boolean;
    address: string | null;
    /** Solo `https`; el servidor ya descarta lo demás. */
    mapUrl: string | null;
    /** Solo si el organizador la hizo pública. */
    capacity: number | null;
}

/** Responsable de una actividad que el organizador marcó visible al público. */
export interface PublicActivityContact {
    name: string;
    phone: string | null;
    email: string | null;
}

export interface PublicActivity extends PublicActivitySummary {
    descriptionHtml: string | null;
    imageUrl: string | null;
    /** Cupo de la actividad; nulo si no tiene. */
    seats: number | null;
    place: PublicPlace | null;
    meetingPoint: string | null;
    transport: string | null;
    contacts: PublicActivityContact[];
}

/** Una página de una lista; `prevUrl` y `nextUrl` son relativas y ya conservan día e idioma. */
export interface Paginated<T> {
    data: T[];
    currentPage: number;
    lastPage: number;
    prevUrl: string | null;
    nextUrl: string | null;
}

/** Enlaces entre las páginas del programa público; todos relativos y con el idioma pedido. */
export interface ProgramNav {
    programUrl: string;
    speakersUrl: string;
    eventUrl: string;
}
