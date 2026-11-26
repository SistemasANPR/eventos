import type {
    CallLandingBlockType,
    DraftColumn,
    DraftFaqItem,
    DraftStepItem,
    DraftTextItem,
    LandingBlockDraft,
    LandingBlockType,
    LocalizedText,
} from '@/types/landing';

/** Tipos que ofrece la biblioteca, en el orden en que se muestran (DA-3). */
export const BLOCK_TYPES: readonly LandingBlockType[] = [
    'hero',
    'rich_text',
    'event_info',
    'calls',
    'tracks',
    'faq',
    'image_gallery',
    'columns',
    'spacer',
];

/** Secciones propias de la landing de una convocatoria (plan 033), en el orden de su plantilla. */
export const CALL_BLOCK_TYPES: readonly CallLandingBlockType[] = [
    'call_hero',
    'call_dates',
    'call_axes',
    'call_requirements',
    'call_steps',
    'call_promos',
];

/** Los tipos que ofrece la biblioteca según el editor: el del evento, o el de una convocatoria (que suma sus secciones). */
export function blockTypesFor(kind: 'event' | 'call'): readonly LandingBlockType[] {
    return kind === 'call' ? [...CALL_BLOCK_TYPES, ...BLOCK_TYPES] : BLOCK_TYPES;
}

/** Límites de la especificación (DA-14): el servidor los hace cumplir; el editor los respeta para no ofrecer lo imposible. */
export const LANDING_LIMITS = { blocks: 30, galleryImages: 12, faqItems: 20, callItems: 12 } as const;

let counter = 0;

/** Identificador temporal de un bloque o elemento nuevo; el servidor lo reemplaza al guardar (CA-022-14). */
export function newClientId(): string {
    counter += 1;

    return `new-${counter}`;
}

/** Un texto vacío en cada idioma del evento. */
export function emptyText(locales: string[]): LocalizedText {
    return Object.fromEntries(locales.map((locale) => [locale, '']));
}

export function emptyFaqItem(locales: string[]): DraftFaqItem {
    return { key: newClientId(), question: emptyText(locales), answer: emptyText(locales) };
}

export function emptyTextItem(locales: string[]): DraftTextItem {
    return { key: newClientId(), text: emptyText(locales) };
}

export function emptyStepItem(locales: string[]): DraftStepItem {
    return { key: newClientId(), icon: 'doc', title: emptyText(locales), text: emptyText(locales) };
}

export function emptyTextColumn(locales: string[]): DraftColumn {
    return { kind: 'text', html: emptyText(locales) };
}

/** Bloque nuevo con valores de partida válidos para su tipo. */
export function createBlock(type: LandingBlockType, locales: string[]): LandingBlockDraft {
    const id = newClientId();

    switch (type) {
        case 'hero':
            return {
                id,
                type,
                title: emptyText(locales),
                subtitle: emptyText(locales),
                image: null,
                imageAlt: emptyText(locales),
                buttonLabel: emptyText(locales),
                buttonUrl: '',
                tone: 'light',
                align: 'start',
            };
        case 'rich_text':
            return { id, type, html: emptyText(locales), width: 'narrow' };
        case 'event_info':
            return { id, type };
        case 'calls':
        case 'tracks':
            return { id, type, title: emptyText(locales) };
        case 'faq':
            return { id, type, title: emptyText(locales), items: [emptyFaqItem(locales)] };
        case 'image_gallery':
            return { id, type, title: emptyText(locales), images: [] };
        case 'spacer':
            return { id, type, size: 'md' };
        case 'columns':
            return { id, type, columns: [emptyTextColumn(locales), emptyTextColumn(locales)] };
        case 'call_hero':
            return {
                id,
                type,
                title: emptyText(locales),
                eventLine: emptyText(locales),
                lede: emptyText(locales),
                notice: emptyText(locales),
                image: null,
                imageAlt: emptyText(locales),
            };
        case 'call_dates':
            return {
                id,
                type,
                timezoneLabel: emptyText(locales),
                resultsLabel: emptyText(locales),
                venueName: emptyText(locales),
                venueNote: emptyText(locales),
            };
        case 'call_axes':
            return { id, type, title: emptyText(locales), intro: emptyText(locales) };
        case 'call_requirements':
            return { id, type, title: emptyText(locales), intro: emptyText(locales), items: [], buttonLabel: emptyText(locales) };
        case 'call_steps':
            return { id, type, title: emptyText(locales), items: [emptyStepItem(locales)] };
        case 'call_promos':
            return { id, type, buttonLabel: emptyText(locales) };
    }
}

/** Mueve el bloque de `index` una posición (`-1` sube, `1` baja). Sin efecto en los extremos. */
export function moveBlock(blocks: LandingBlockDraft[], index: number, direction: -1 | 1): LandingBlockDraft[] {
    const target = index + direction;
    const block = blocks[index];
    const other = blocks[target];

    if (block === undefined || other === undefined) {
        return blocks;
    }

    const next = [...blocks];
    next[index] = other;
    next[target] = block;

    return next;
}

/** Copia el bloque justo debajo del original, con identificadores nuevos. */
export function duplicateBlock(blocks: LandingBlockDraft[], index: number): LandingBlockDraft[] {
    const block = blocks[index];

    if (block === undefined) {
        return blocks;
    }

    const copy = structuredClone(block);
    copy.id = newClientId();

    if (copy.type === 'faq') {
        copy.items = copy.items.map((item) => ({ ...item, key: newClientId() }));
    }

    if (copy.type === 'image_gallery') {
        copy.images = copy.images.map((image) => ({ ...image, key: newClientId() }));
    }

    return [...blocks.slice(0, index + 1), copy, ...blocks.slice(index + 1)];
}

export function removeBlock(blocks: LandingBlockDraft[], index: number): LandingBlockDraft[] {
    return blocks.filter((_, position) => position !== index);
}

/** `true` si se puede añadir un bloque más (límite de la especificación). */
export function canAddBlock(blocks: LandingBlockDraft[]): boolean {
    return blocks.length < LANDING_LIMITS.blocks;
}
