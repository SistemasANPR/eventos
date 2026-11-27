/** Nombre de cada icono del diseño de la landing (ver `Icon`). */
export type LandingIconName =
    | 'hoja'
    | 'personas'
    | 'plano'
    | 'chip'
    | 'ciudad'
    | 'monedas'
    | 'calendario'
    | 'reloj'
    | 'premio'
    | 'pin'
    | 'check'
    | 'doc'
    | 'enviar'
    | 'lupa'
    | 'micro'
    | 'impresora'
    | 'flecha';

/** Texto con partes en negrita: lo que va entre `**` se dibuja en `<strong>`. */
export type RichText = string;

/** Un bloque de la respuesta de una pregunta frecuente. */
export type FaqBlock = { paragraph: RichText } | { list: RichText[] } | { html: string };

export interface LandingAxis {
    icon: LandingIconName;
    name: string;
    description: string;
}

export interface LandingFormField {
    name: string;
    note?: string;
    isRequired?: boolean;
}

export interface LandingFormGroup {
    title: string;
    fields: LandingFormField[];
}

export interface LandingFileCard {
    extension: string;
    title: string;
    notes: RichText[];
}

/** Lo que acompaña a los requisitos: la vista previa del formulario o las tarjetas de los archivos. */
export type LandingRequirementsAside =
    | { kind: 'form'; title: string; requiredNote: string; groups: LandingFormGroup[] }
    | { kind: 'files'; files: LandingFileCard[]; reminder: RichText };

export interface LandingStep {
    icon: LandingIconName;
    title: string;
    text: string;
}

export interface LandingFaq {
    question: string;
    answer: FaqBlock[];
}

/** El anuncio que invita a otra convocatoria del mismo evento. */
export interface LandingPromo {
    url: string;
    title: string;
    text: string;
    button: string;
    /** Sin portada, el anuncio se muestra solo con su texto. */
    coverUrl?: string;
}

/** Lo que dibuja la landing: se arma con los datos de la convocatoria y, si lo hay, el retoque de su texto (`buildLandingContent`). */
export interface CallLandingContent {
    title: string;
    eventLine: string;
    lede: string;
    /** Portada de la convocatoria; sin ella se usa el logotipo del evento, y sin logotipo no hay imagen. */
    cover?: { url: string; alt: string; isLogo?: boolean };
    /** Aviso destacado bajo el texto de la portada (por ejemplo, que no hace falta ser miembro). */
    notice?: string;
    seo: { description: string; ogTitle: string; ogDescription: string };
    /** Etiqueta de la zona horaria que se muestra junto a la hora de cierre. */
    timezoneLabel: string;
    /** Texto de la ficha de resultados; sin él, se usa la fecha de resultados de la convocatoria (si la hay). */
    resultsLabel?: string;
    /** Ficha de la sede; solo aparece si la convocatoria tiene este texto propio. */
    venue?: { name: string; note: string };
    axes: { heading: string; intro: string; items: LandingAxis[] };
    requirements: {
        heading: string;
        intro?: string;
        items: RichText[];
        formButton: string;
        aside?: LandingRequirementsAside;
    };
    steps: LandingStep[];
    faqs: LandingFaq[];
    promos: LandingPromo[];
}

/** Datos de la convocatoria que entrega el servidor (`CallLandingData` en PHP). */
export interface CallLandingData {
    minParticipants: number;
    maxParticipants: number;
    formProposal: { name: string; isRequired: boolean }[];
    formParticipant: { name: string; isRequired: boolean }[];
    otherCalls: { slug: string; title: string; description: string | null; url: string }[];
}

/** Retoques de texto o portada de una convocatoria: lo que se escribe aquí reemplaza al texto automático. */
export type CallLandingOverride = Partial<Omit<CallLandingContent, 'promos'>> & {
    /** Por convocatoria hermana (su slug): texto, botón o portada propios de su anuncio. */
    promos?: Record<string, Partial<Omit<LandingPromo, 'url'>>>;
};

/** Rutas que identifican una convocatoria. */
export interface CallRouteSlugs {
    organization: string;
    event: string;
    call: string;
}
