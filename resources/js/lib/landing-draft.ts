import type {
    DraftColumn,
    LandingBlockDraft,
    LandingBlockType,
    LandingPreviewData,
    LocalizedText,
    PublicColumn,
    PublicLandingBlock,
} from '@/types/landing';

/** Texto en el idioma pedido; si falta o está vacío, el del idioma base (plan 022, DA-5); si tampoco, vacío. */
export function pickText(text: LocalizedText, locale: string, defaultLocale: string): string {
    const wanted = text[locale]?.trim() ?? '';

    return wanted !== '' ? (text[locale] ?? '') : (text[defaultLocale] ?? '');
}

/** Como `pickText`, pero `null` cuando no hay texto en ningún idioma (las secciones usan su título predeterminado). */
export function pickOptionalText(text: LocalizedText, locale: string, defaultLocale: string): string | null {
    const value = pickText(text, locale, defaultLocale);

    return value.trim() === '' ? null : value;
}

/** Idiomas de `locales` en que el texto está vacío. */
export function missingLocales(text: LocalizedText, locales: string[]): string[] {
    return locales.filter((locale) => (text[locale] ?? '').trim() === '');
}

/** Todos los textos por idioma de un bloque, con la ruta de su campo (`title`, `items.0.question`...). */
export function localizedFields(block: LandingBlockDraft): Array<{ path: string; text: LocalizedText; isRequired: boolean }> {
    switch (block.type) {
        case 'hero':
            return [
                { path: 'title', text: block.title, isRequired: true },
                { path: 'subtitle', text: block.subtitle, isRequired: false },
                ...(block.image === null ? [] : [{ path: 'imageAlt', text: block.imageAlt, isRequired: true }]),
                ...(block.buttonUrl === '' ? [] : [{ path: 'buttonLabel', text: block.buttonLabel, isRequired: true }]),
            ];
        case 'rich_text':
            return [{ path: 'html', text: block.html, isRequired: true }];
        case 'calls':
        case 'tracks':
            return [{ path: 'title', text: block.title, isRequired: false }];
        case 'faq':
            return [
                { path: 'title', text: block.title, isRequired: false },
                ...block.items.flatMap((item, index) => [
                    { path: `items.${index}.question`, text: item.question, isRequired: true },
                    { path: `items.${index}.answer`, text: item.answer, isRequired: true },
                ]),
            ];
        case 'image_gallery':
            return [
                { path: 'title', text: block.title, isRequired: false },
                ...block.images.map((image, index) => ({ path: `images.${index}.alt`, text: image.alt, isRequired: true })),
            ];
        case 'columns':
            return block.columns.flatMap((column, index) =>
                column.kind === 'text'
                    ? [{ path: `columns.${index}.html`, text: column.html, isRequired: true }]
                    : column.image === null
                      ? []
                      : [{ path: `columns.${index}.alt`, text: column.alt, isRequired: true }],
            );
        case 'call_hero':
            return [
                { path: 'title', text: block.title, isRequired: false },
                { path: 'eventLine', text: block.eventLine, isRequired: false },
                { path: 'lede', text: block.lede, isRequired: false },
                { path: 'notice', text: block.notice, isRequired: false },
                ...(block.image === null ? [] : [{ path: 'imageAlt', text: block.imageAlt, isRequired: true }]),
            ];
        case 'call_dates':
            return [
                { path: 'timezoneLabel', text: block.timezoneLabel, isRequired: false },
                { path: 'resultsLabel', text: block.resultsLabel, isRequired: false },
                { path: 'venueName', text: block.venueName, isRequired: false },
                { path: 'venueNote', text: block.venueNote, isRequired: false },
            ];
        case 'call_axes':
            return [
                { path: 'title', text: block.title, isRequired: false },
                { path: 'intro', text: block.intro, isRequired: false },
            ];
        case 'call_requirements':
            return [
                { path: 'title', text: block.title, isRequired: false },
                { path: 'intro', text: block.intro, isRequired: false },
                ...block.items.map((item, index) => ({ path: `items.${index}.text`, text: item.text, isRequired: true })),
                { path: 'buttonLabel', text: block.buttonLabel, isRequired: false },
            ];
        case 'call_steps':
            return [
                { path: 'title', text: block.title, isRequired: false },
                ...block.items.flatMap((item, index) => [
                    { path: `items.${index}.title`, text: item.title, isRequired: true },
                    { path: `items.${index}.text`, text: item.text, isRequired: true },
                ]),
            ];
        case 'call_promos':
            return [{ path: 'buttonLabel', text: block.buttonLabel, isRequired: false }];
        case 'event_info':
        case 'spacer':
            return [];
    }
}

/** Cuántos textos obligatorios faltan en el idioma `locale` de un bloque. */
export function countMissingTexts(block: LandingBlockDraft, locale: string): number {
    return localizedFields(block).filter(({ text, isRequired }) => isRequired && (text[locale] ?? '').trim() === '').length;
}

/**
 * Convierte un bloque del borrador en el bloque público que dibuja `LandingRenderer`, en el idioma
 * pedido con respaldo al base. Es lo que hace el servidor al publicar; aquí solo alimenta el lienzo.
 */
export function resolveDraftBlock(
    block: LandingBlockDraft,
    locale: string,
    defaultLocale: string,
    preview: LandingPreviewData,
    /** Texto de relleno de la plantilla de convocatoria por clave (`axes_title`...); sin él, vacío. */
    template: (key: string) => string = () => '',
): PublicLandingBlock {
    const text = (value: LocalizedText): string => pickText(value, locale, defaultLocale);
    const optional = (value: LocalizedText): string | null => pickOptionalText(value, locale, defaultLocale);
    const orDefault = (value: LocalizedText, key: string): string => optional(value) ?? template(key);
    const call = preview.call;

    switch (block.type) {
        case 'hero': {
            const label = text(block.buttonLabel);

            return {
                id: block.id,
                type: 'hero',
                title: text(block.title),
                subtitle: optional(block.subtitle),
                imageUrl: block.image?.url ?? null,
                imageAlt: block.image === null ? null : text(block.imageAlt),
                button: block.buttonUrl !== '' && label !== '' ? { label, url: block.buttonUrl } : null,
                tone: block.tone,
                align: block.align,
            };
        }
        case 'rich_text':
            return { id: block.id, type: 'rich_text', html: text(block.html), width: block.width };
        case 'event_info':
            return { id: block.id, type: 'event_info', ...preview.event };
        case 'calls':
            return { id: block.id, type: 'calls', title: optional(block.title), calls: preview.calls };
        case 'tracks':
            return { id: block.id, type: 'tracks', title: optional(block.title), tracks: preview.tracks };
        case 'faq':
            return {
                id: block.id,
                type: 'faq',
                title: optional(block.title),
                items: block.items.map((item) => ({ question: text(item.question), answerHtml: text(item.answer) })),
            };
        case 'image_gallery':
            return {
                id: block.id,
                type: 'image_gallery',
                title: optional(block.title),
                images: block.images.map((image) => ({ url: image.image.url, alt: text(image.alt) })),
            };
        case 'spacer':
            return { id: block.id, type: 'spacer', size: block.size };
        case 'columns':
            return {
                id: block.id,
                type: 'columns',
                columns: [resolveColumn(block.columns[0], text), resolveColumn(block.columns[1], text)],
            };
        case 'call_hero':
            return {
                id: block.id,
                type: 'call_hero',
                title: optional(block.title) ?? call?.call.title ?? '',
                eventLine: optional(block.eventLine) ?? preview.event.name,
                lede: optional(block.lede) ?? call?.call.description ?? '',
                notice: optional(block.notice),
                imageUrl: block.image?.url ?? null,
                imageAlt: block.image === null ? null : optional(block.imageAlt),
            };
        case 'call_dates': {
            const venue = text(block.venueName);

            return {
                id: block.id,
                type: 'call_dates',
                timezoneLabel: optional(block.timezoneLabel),
                resultsLabel: optional(block.resultsLabel),
                venue: venue === '' ? null : { name: venue, note: text(block.venueNote) },
            };
        }
        case 'call_axes':
            return {
                id: block.id,
                type: 'call_axes',
                title: orDefault(block.title, 'axes_title'),
                intro: orDefault(block.intro, 'axes_intro'),
                axes: preview.tracks,
            };
        case 'call_requirements':
            return {
                id: block.id,
                type: 'call_requirements',
                title: orDefault(block.title, 'requirements_title'),
                intro: orDefault(block.intro, 'requirements_intro'),
                items: [...(call?.liveItems ?? []), ...block.items.map((item) => text(item.text)).filter((item) => item !== '')],
                buttonLabel: orDefault(block.buttonLabel, 'requirements_button'),
                form: call?.form ?? null,
            };
        case 'call_steps':
            return {
                id: block.id,
                type: 'call_steps',
                title: orDefault(block.title, 'steps_title'),
                steps: block.items.map((item) => ({ icon: item.icon, title: text(item.title), text: text(item.text) })),
            };
        case 'call_promos':
            return {
                id: block.id,
                type: 'call_promos',
                promos: (call?.promos ?? []).map((promo) => ({ ...promo, button: orDefault(block.buttonLabel, 'promo_button') })),
            };
    }
}

function resolveColumn(column: DraftColumn, text: (value: LocalizedText) => string): PublicColumn {
    if (column.kind === 'text') {
        return { kind: 'text', html: text(column.html) };
    }

    return column.image === null ? { kind: 'text', html: '' } : { kind: 'image', url: column.image.url, alt: text(column.alt) };
}

/** Tipo del bloque como clave de i18n del editor: `landing_editor.block.hero`. */
export function blockLabelKey(type: LandingBlockType): string {
    return `landing_editor.block.${type}`;
}
