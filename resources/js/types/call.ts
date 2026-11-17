/** Estado público de una convocatoria: coincide con `App\Enums\CallState`. */
export type CallState = 'scheduled' | 'open' | 'paused' | 'closed';

/** Eje temático activo: solo nombre y descripción, sin identificadores. */
export interface PublicTrack {
    name: string;
    description: string | null;
}

/** Convocatoria pública: coincide con `App\Support\Calls\PublicCallData`. */
export interface PublicCall {
    title: string;
    description: string | null;
    /** Nombre del tipo de propuesta, ya traducido. */
    submissionType: string;
    state: CallState;
    /** Apertura en UTC (ISO 8601). */
    opensAt: string;
    /** Cierre en UTC (ISO 8601). */
    closesAt: string;
    /** Resultados en UTC (ISO 8601); ausente mientras no se defina. */
    resultsAt?: string;
    /** Zona horaria IANA del evento, solo para mostrar las fechas. */
    timezone: string;
    /** Idioma de esta respuesta. */
    locale: string;
    /** Idiomas de la convocatoria. */
    locales: string[];
    tracks: PublicTrack[];
}
