import type { CallState, PublicCall, PublicTrack } from '@/types/call';
import type { LandingIconName } from '@/types/call-landing';
import type { TimeFormat } from '@/types/event';
import type { Translations } from '@/types/inertia';

/*
 * Contrato de tipos de la landing del evento (plan 022). Dos mitades:
 *
 *  1. `PublicLandingBlock`: lo que recibe el sitio público. Ya resuelto en el servidor: textos en el
 *     idioma pedido (o el base), fechas e identificadores listos para mostrar, URLs de imágenes
 *     construidas. Nunca incluye el borrador ni identificadores internos.
 *  2. `LandingBlockDraft`: el borrador que edita el organizador, con cada texto en todos los idiomas
 *     del evento. Lo que el editor envía a `saveDraft` y `publish`; el servidor valida cada bloque
 *     con su clase de reglas y fija los identificadores (los `id` que genera el cliente se ignoran).
 */

/** Tipos de bloque de la lista blanca (DA-3): coincide con `App\Enums\LandingBlockType`. */
export type LandingBlockType =
    'hero' | 'rich_text' | 'event_info' | 'calls' | 'tracks' | 'faq' | 'image_gallery' | 'spacer' | 'columns' | CallLandingBlockType;

/** Secciones propias de la landing de una convocatoria (plan 033). */
export type CallLandingBlockType = 'call_hero' | 'call_dates' | 'call_axes' | 'call_requirements' | 'call_steps' | 'call_promos';

/** Variantes de aspecto (DA-15): sobre tokens, nunca colores libres. */
export type LandingTone = 'light' | 'accent';
export type LandingAlign = 'start' | 'center';
export type LandingWidth = 'narrow' | 'wide';
export type LandingSpacerSize = 'sm' | 'md' | 'lg';

/* ------------------------------------------------------------------ */
/* Sitio público: bloques ya resueltos                                  */
/* ------------------------------------------------------------------ */

interface PublicBlockBase {
    /** Identificador del bloque (generado por el servidor); solo sirve de clave de lista. */
    id: string;
}

/** Botón con enlace `https` externo. */
export interface PublicLandingButton {
    label: string;
    url: string;
}

export interface PublicHeroBlock extends PublicBlockBase {
    type: 'hero';
    title: string;
    subtitle: string | null;
    /** URL de la imagen de fondo ya construida por el servidor, o `null`. */
    imageUrl: string | null;
    imageAlt: string | null;
    button: PublicLandingButton | null;
    tone: LandingTone;
    align: LandingAlign;
}

export interface PublicRichTextBlock extends PublicBlockBase {
    type: 'rich_text';
    /** HTML ya saneado por el servidor (`RichTextSanitizer`). */
    html: string;
    width: LandingWidth;
}

/** Datos del evento, tomados del evento (no se copian a la landing). */
export interface PublicEventInfoBlock extends PublicBlockBase {
    type: 'event_info';
    name: string;
    description: string | null;
    /** Instantes en UTC (ISO 8601). */
    startsAt: string;
    endsAt: string;
    /** Zona IANA del evento, solo para mostrar las fechas. */
    timezone: string;
    timeFormat: TimeFormat;
    venueName: string | null;
    city: string | null;
}

/** Convocatoria publicada del evento, con el estado calculado al mostrar la página. */
export interface PublicLandingCall {
    title: string;
    /** Nombre del tipo de propuesta, ya traducido. */
    submissionType: string;
    state: CallState;
    /** Instantes en UTC (ISO 8601). */
    opensAt: string;
    closesAt: string;
    timezone: string;
    timeFormat: TimeFormat;
    /** URL de la página de la convocatoria; `null` si no está abierta (botón bloqueado, DA-17). */
    registerUrl: string | null;
}

export interface PublicCallsBlock extends PublicBlockBase {
    type: 'calls';
    /** Título de la sección; `null` usa el texto predeterminado. */
    title: string | null;
    calls: PublicLandingCall[];
}

export interface PublicTracksBlock extends PublicBlockBase {
    type: 'tracks';
    title: string | null;
    tracks: PublicTrack[];
}

export interface PublicFaqItem {
    question: string;
    /** HTML ya saneado. */
    answerHtml: string;
}

export interface PublicFaqBlock extends PublicBlockBase {
    type: 'faq';
    title: string | null;
    items: PublicFaqItem[];
}

export interface PublicGalleryImage {
    url: string;
    alt: string;
}

export interface PublicImageGalleryBlock extends PublicBlockBase {
    type: 'image_gallery';
    title: string | null;
    images: PublicGalleryImage[];
}

export interface PublicSpacerBlock extends PublicBlockBase {
    type: 'spacer';
    size: LandingSpacerSize;
}

export type PublicColumn = { kind: 'text'; html: string } | { kind: 'image'; url: string; alt: string };

export interface PublicColumnsBlock extends PublicBlockBase {
    type: 'columns';
    columns: [PublicColumn, PublicColumn];
}

/* Secciones de la landing de una convocatoria (plan 033): ya resueltas; las fechas, el estado y el botón al formulario salen de la propia convocatoria. */

export interface PublicCallHeroBlock extends PublicBlockBase {
    type: 'call_hero';
    title: string;
    /** Línea bajo el título (el evento). */
    eventLine: string;
    lede: string;
    notice: string | null;
    /** Portada propia de la convocatoria; sin ella se usa el logotipo del evento. */
    imageUrl: string | null;
    imageAlt: string | null;
}

export interface PublicCallDatesBlock extends PublicBlockBase {
    type: 'call_dates';
    timezoneLabel: string | null;
    resultsLabel: string | null;
    venue: { name: string; note: string } | null;
}

export interface PublicCallAxesBlock extends PublicBlockBase {
    type: 'call_axes';
    title: string;
    intro: string;
    axes: PublicTrack[];
}

export interface PublicFormField {
    name: string;
    isRequired: boolean;
}

export interface PublicCallForm {
    title: string;
    requiredNote: string;
    groups: { title: string; fields: PublicFormField[] }[];
}

export interface PublicCallRequirementsBlock extends PublicBlockBase {
    type: 'call_requirements';
    title: string;
    intro: string | null;
    /** Texto con partes en negrita entre `**`. */
    items: string[];
    buttonLabel: string;
    /** Vista previa de lo que pide el formulario publicado; `null` si la convocatoria no tiene uno. */
    form: PublicCallForm | null;
}

export interface PublicCallStep {
    icon: LandingIconName;
    title: string;
    text: string;
}

export interface PublicCallStepsBlock extends PublicBlockBase {
    type: 'call_steps';
    title: string;
    steps: PublicCallStep[];
}

export interface PublicCallPromo {
    url: string;
    title: string;
    text: string;
    button: string;
    coverUrl: string | null;
}

export interface PublicCallPromosBlock extends PublicBlockBase {
    type: 'call_promos';
    promos: PublicCallPromo[];
}

export type PublicLandingBlock =
    | PublicCallHeroBlock
    | PublicCallDatesBlock
    | PublicCallAxesBlock
    | PublicCallRequirementsBlock
    | PublicCallStepsBlock
    | PublicCallPromosBlock
    | PublicHeroBlock
    | PublicRichTextBlock
    | PublicEventInfoBlock
    | PublicCallsBlock
    | PublicTracksBlock
    | PublicFaqBlock
    | PublicImageGalleryBlock
    | PublicSpacerBlock
    | PublicColumnsBlock;

/** Prop `landing` de `Events/Show`: `null` (o ausente) conserva la página actual del evento. */
export interface PublicLanding {
    blocks: PublicLandingBlock[];
}

/* ------------------------------------------------------------------ */
/* Editor: borrador con textos por idioma                              */
/* ------------------------------------------------------------------ */

/** Un texto por idioma del evento: `{ es: 'Hola', en: 'Hello' }`. Una clave ausente o vacía es un texto faltante. */
export type LocalizedText = Record<string, string>;

/**
 * Imagen del borrador. El bloque guarda solo el identificador (`id`); `url` es la ruta autenticada
 * con que el editor la ve (solo lectura: el servidor la ignora al guardar).
 */
export interface DraftImageRef {
    id: string;
    url: string;
}

interface DraftBlockBase {
    /** Generado por el servidor. Un bloque nuevo lleva uno temporal del cliente (`new-…`) que el servidor reemplaza. */
    id: string;
}

export interface DraftHeroBlock extends DraftBlockBase {
    type: 'hero';
    title: LocalizedText;
    subtitle: LocalizedText;
    image: DraftImageRef | null;
    imageAlt: LocalizedText;
    buttonLabel: LocalizedText;
    /** `https` externo o vacío (sin botón). */
    buttonUrl: string;
    tone: LandingTone;
    align: LandingAlign;
}

export interface DraftRichTextBlock extends DraftBlockBase {
    type: 'rich_text';
    html: LocalizedText;
    width: LandingWidth;
}

export interface DraftEventInfoBlock extends DraftBlockBase {
    type: 'event_info';
}

export interface DraftCallsBlock extends DraftBlockBase {
    type: 'calls';
    title: LocalizedText;
}

export interface DraftTracksBlock extends DraftBlockBase {
    type: 'tracks';
    title: LocalizedText;
}

export interface DraftFaqItem {
    /** Clave de lista del cliente; el servidor no la guarda. */
    key: string;
    question: LocalizedText;
    answer: LocalizedText;
}

export interface DraftFaqBlock extends DraftBlockBase {
    type: 'faq';
    title: LocalizedText;
    items: DraftFaqItem[];
}

export interface DraftGalleryImage {
    key: string;
    image: DraftImageRef;
    alt: LocalizedText;
}

export interface DraftImageGalleryBlock extends DraftBlockBase {
    type: 'image_gallery';
    title: LocalizedText;
    images: DraftGalleryImage[];
}

export interface DraftSpacerBlock extends DraftBlockBase {
    type: 'spacer';
    size: LandingSpacerSize;
}

export type DraftColumn = { kind: 'text'; html: LocalizedText } | { kind: 'image'; image: DraftImageRef | null; alt: LocalizedText };

export interface DraftColumnsBlock extends DraftBlockBase {
    type: 'columns';
    columns: [DraftColumn, DraftColumn];
}

/* Secciones de la landing de una convocatoria (plan 033): borrador con textos por idioma. */

export interface DraftCallHeroBlock extends DraftBlockBase {
    type: 'call_hero';
    /** Vacío = el título de la convocatoria. */
    title: LocalizedText;
    /** Vacío = el nombre del evento. */
    eventLine: LocalizedText;
    /** Vacío = la descripción de la convocatoria. */
    lede: LocalizedText;
    notice: LocalizedText;
    /** Vacío = el logotipo del evento. */
    image: DraftImageRef | null;
    imageAlt: LocalizedText;
}

export interface DraftCallDatesBlock extends DraftBlockBase {
    type: 'call_dates';
    timezoneLabel: LocalizedText;
    resultsLabel: LocalizedText;
    venueName: LocalizedText;
    venueNote: LocalizedText;
}

export interface DraftCallAxesBlock extends DraftBlockBase {
    type: 'call_axes';
    title: LocalizedText;
    intro: LocalizedText;
}

export interface DraftTextItem {
    key: string;
    text: LocalizedText;
}

export interface DraftCallRequirementsBlock extends DraftBlockBase {
    type: 'call_requirements';
    title: LocalizedText;
    intro: LocalizedText;
    items: DraftTextItem[];
    buttonLabel: LocalizedText;
}

export interface DraftStepItem {
    key: string;
    icon: LandingIconName;
    title: LocalizedText;
    text: LocalizedText;
}

export interface DraftCallStepsBlock extends DraftBlockBase {
    type: 'call_steps';
    title: LocalizedText;
    items: DraftStepItem[];
}

export interface DraftCallPromosBlock extends DraftBlockBase {
    type: 'call_promos';
    buttonLabel: LocalizedText;
}

/** Las secciones propias de la landing de una convocatoria. */
export type CallLandingBlockDraft =
    DraftCallHeroBlock | DraftCallDatesBlock | DraftCallAxesBlock | DraftCallRequirementsBlock | DraftCallStepsBlock | DraftCallPromosBlock;

export type LandingBlockDraft =
    | CallLandingBlockDraft
    | DraftHeroBlock
    | DraftRichTextBlock
    | DraftEventInfoBlock
    | DraftCallsBlock
    | DraftTracksBlock
    | DraftFaqBlock
    | DraftImageGalleryBlock
    | DraftSpacerBlock
    | DraftColumnsBlock;

/* ------------------------------------------------------------------ */
/* Editor: props y API que monta el panel                              */
/* ------------------------------------------------------------------ */

/**
 * Datos del evento con que el lienzo dibuja los bloques que no guardan copia
 * (`event_info`, `calls`, `tracks`). Los resuelve el servidor en el idioma base; el lienzo solo
 * los muestra.
 */
export interface LandingPreviewData {
    event: Omit<PublicEventInfoBlock, 'id' | 'type'>;
    calls: PublicLandingCall[];
    tracks: PublicTrack[];
    /** Solo en el editor de una convocatoria (plan 033): lo que sus secciones leen de ella. */
    call?: LandingCallPreview;
}

export interface LandingCallPreview {
    /** La convocatoria como la ve el público (un borrador cuenta como «próximamente»). */
    call: PublicCall;
    logoUrl: string | null;
    /** Líneas de requisitos que salen de los datos reales (límite de personas, ejes). */
    liveItems: string[];
    form: PublicCallForm | null;
    promos: PublicCallPromo[];
}

export interface LandingEditorProps {
    /** Borrador actual. */
    blocks: LandingBlockDraft[];
    /** Versión optimista de la landing: se envía en cada guardado. */
    version: number;
    /** Idiomas del evento, el primero es el base. */
    locales: string[];
    defaultLocale: string;
    /** Textos de `lang/` (mismo formato que `SharedProps.translations`). */
    translations: Translations;
    preview: LandingPreviewData;
    /** URL de la landing pública, para «Ver landing»; `null` si el evento no está publicado (no se ofrece el enlace). */
    publicUrl: string | null;
    /** `true` si el borrador difiere de la versión publicada al abrir el editor. */
    hasUnpublishedChanges: boolean;
    /** `call` en el editor de la landing de una convocatoria (plan 033): ofrece sus secciones y su plantilla. */
    kind?: 'event' | 'call';
    /** La plantilla de convocatoria, lista para aplicar; `null` en el editor del evento. */
    template?: LandingBlockDraft[] | null;
}

/** Errores de validación del servidor, por ruta de campo (`blocks.2.title.es`). */
export type LandingFieldErrors = Record<string, string[]>;

export type LandingSaveResult =
    | { ok: true; version: number; blocks: LandingBlockDraft[] }
    | {
          ok: false;
          reason: 'conflict';
          version: number;
          blocks: LandingBlockDraft[];
      }
    | { ok: false; reason: 'invalid'; errors: LandingFieldErrors };

export type LandingDiscardResult = {
    ok: true;
    version: number;
    blocks: LandingBlockDraft[];
};

export type LandingUploadResult = { ok: true; image: DraftImageRef } | { ok: false; errors: string[] };

/**
 * Persistencia del editor. El backend la conecta con Livewire (`$wire`). Las promesas rechazadas
 * (red, error 500) se muestran como error y no pierden el trabajo del editor.
 */
export interface LandingEditorApi {
    /** Guarda el borrador sin publicar. */
    saveDraft: (blocks: LandingBlockDraft[], version: number) => Promise<LandingSaveResult>;
    /** «Actualizar»: guarda y publica en una sola operación. */
    publish: (blocks: LandingBlockDraft[], version: number) => Promise<LandingSaveResult>;
    /** Vuelve el borrador a la versión publicada. */
    discard: () => Promise<LandingDiscardResult>;
    /** Sube una imagen (JPEG, PNG o WebP) y devuelve su identificador y la URL con que el editor la ve. */
    uploadImage: (file: File) => Promise<LandingUploadResult>;
}
