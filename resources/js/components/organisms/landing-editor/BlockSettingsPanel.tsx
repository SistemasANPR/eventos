import { useId } from 'react';

import { ChoiceField } from '@/components/molecules/ChoiceField';
import { CallBlockForm } from '@/components/organisms/landing-editor/CallBlockForm';
import { ImageField } from '@/components/molecules/ImageField';
import { LocaleTabs } from '@/components/molecules/LocaleTabs';
import { LocalizedField } from '@/components/molecules/LocalizedField';
import { RichTextField } from '@/components/molecules/RichTextField';
import { BUTTON_SECONDARY, EDITOR_ITEM_FIELDSET, FORM_CONTROL, HEADING_LG } from '@/lib/classes';
import { isHttpsUrl } from '@/lib/html-edit';
import { emptyFaqItem, emptyText, emptyTextColumn, LANDING_LIMITS, newClientId } from '@/lib/landing-blocks';
import { blockLabelKey, localizedFields, missingLocales } from '@/lib/landing-draft';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type {
    DraftColumn,
    DraftImageRef,
    LandingAlign,
    LandingBlockDraft,
    LandingFieldErrors,
    LandingSpacerSize,
    LandingTone,
    LandingUploadResult,
    LandingWidth,
} from '@/types/landing';

interface BlockSettingsPanelProps {
    /** Bloque seleccionado, o `null` si no hay ninguno. */
    block: LandingBlockDraft | null;
    /** Posición (desde 0) del bloque en la lista, para ubicar los errores del servidor. */
    index: number;
    locales: string[];
    defaultLocale: string;
    /** Idioma de la pestaña activa. */
    activeLocale: string;
    onLocaleChange: (locale: string) => void;
    onChange: (block: LandingBlockDraft) => void;
    onUpload: (file: File) => Promise<LandingUploadResult>;
    errors: LandingFieldErrors;
    t: Translate;
}

/**
 * Panel de ajustes del bloque seleccionado: un formulario por tipo de bloque, con pestañas por
 * idioma del evento y marca en los textos que faltan. No guarda: cada cambio sube al editor.
 * Usar en: `LandingEditor`.
 */
export function BlockSettingsPanel({
    block,
    index,
    locales,
    defaultLocale,
    activeLocale,
    onLocaleChange,
    onChange,
    onUpload,
    errors,
    t,
}: BlockSettingsPanelProps) {
    const panelId = useId();

    if (block === null) {
        return (
            <section aria-labelledby={`${panelId}-heading`} className="flex flex-col gap-2">
                <h2 id={`${panelId}-heading`} className={HEADING_LG}>
                    {t('landing_editor.settings.title')}
                </h2>
                <p className="text-base text-text-muted">{t('landing_editor.settings.empty')}</p>
            </section>
        );
    }

    const incomplete = locales.filter((locale) =>
        localizedFields(block).some(({ text, isRequired }) => isRequired && missingLocales(text, [locale]).length > 0),
    );
    const fieldErrors = (path: string, localized: boolean): string[] =>
        errors[`blocks.${index}.${path}${localized ? `.${activeLocale}` : ''}`] ?? [];
    const common = { locale: activeLocale, defaultLocale, t };
    const fieldId = (name: string): string => `${panelId}-${name}`;

    return (
        <section aria-labelledby={`${panelId}-heading`} className="flex flex-col gap-4">
            <h2 id={`${panelId}-heading`} className={HEADING_LG}>
                {t('landing_editor.settings.block_title', { block: t(blockLabelKey(block.type)) })}
            </h2>
            {locales.length > 1 && localizedFields(block).length > 0 && (
                <LocaleTabs
                    locales={locales}
                    active={activeLocale}
                    onChange={onLocaleChange}
                    missing={incomplete}
                    panelId={panelId}
                    t={t}
                />
            )}
            <div id={panelId} role={locales.length > 1 ? 'tabpanel' : undefined} className="flex flex-col gap-4">
                {renderForm()}
            </div>
        </section>
    );

    function renderForm() {
        switch (block?.type) {
            case 'hero':
                return renderHero(block);
            case 'rich_text':
                return (
                    <>
                        <RichTextField
                            id={fieldId('html')}
                            label={t('landing_editor.field.text')}
                            value={block.html}
                            onChange={(html) => onChange({ ...block, html })}
                            isRequired
                            errors={fieldErrors('html', true)}
                            {...common}
                        />
                        <ChoiceField
                            id={fieldId('width')}
                            label={t('landing_editor.field.width')}
                            value={block.width}
                            options={widthOptions(t)}
                            onChange={(width) => onChange({ ...block, width })}
                        />
                    </>
                );
            case 'event_info':
                return <p className="text-base text-text-muted">{t('landing_editor.settings.event_info_note')}</p>;
            case 'calls':
            case 'tracks':
                return (
                    <>
                        <LocalizedField
                            id={fieldId('title')}
                            label={t('landing_editor.field.section_title')}
                            hint={t('landing_editor.field.section_title_hint')}
                            value={block.title}
                            onChange={(title) => onChange({ ...block, title })}
                            errors={fieldErrors('title', true)}
                            {...common}
                        />
                        <p className="text-base text-text-muted">{t(`landing_editor.settings.${block.type}_note`)}</p>
                    </>
                );
            case 'faq':
                return renderFaq(block);
            case 'image_gallery':
                return renderGallery(block);
            case 'spacer':
                return (
                    <ChoiceField
                        id={fieldId('size')}
                        label={t('landing_editor.field.size')}
                        value={block.size}
                        options={sizeOptions(t)}
                        onChange={(size) => onChange({ ...block, size })}
                    />
                );
            case 'columns':
                return block.columns.map((column, position) => renderColumn(block, column, position));
            case 'call_hero':
            case 'call_dates':
            case 'call_axes':
            case 'call_requirements':
            case 'call_steps':
            case 'call_promos':
                return (
                    <CallBlockForm
                        block={block}
                        onChange={onChange}
                        onUpload={onUpload}
                        locales={locales}
                        locale={activeLocale}
                        defaultLocale={defaultLocale}
                        fieldErrors={fieldErrors}
                        fieldId={fieldId}
                        t={t}
                    />
                );
            default:
                return null;
        }
    }

    function renderHero(hero: Extract<LandingBlockDraft, { type: 'hero' }>) {
        const urlErrors = fieldErrors('buttonUrl', false);
        const isUrlInvalid = hero.buttonUrl !== '' && !isHttpsUrl(hero.buttonUrl);

        return (
            <>
                <LocalizedField
                    id={fieldId('title')}
                    label={t('landing_editor.field.title')}
                    value={hero.title}
                    onChange={(title) => onChange({ ...hero, title })}
                    isRequired
                    errors={fieldErrors('title', true)}
                    {...common}
                />
                <LocalizedField
                    id={fieldId('subtitle')}
                    label={t('landing_editor.field.subtitle')}
                    value={hero.subtitle}
                    onChange={(subtitle) => onChange({ ...hero, subtitle })}
                    isMultiline
                    errors={fieldErrors('subtitle', true)}
                    {...common}
                />
                <ImageField
                    label={t('landing_editor.field.image')}
                    image={hero.image}
                    onChange={(image) => onChange({ ...hero, image })}
                    onUpload={onUpload}
                    errors={fieldErrors('image', false)}
                    t={t}
                />
                {hero.image !== null && (
                    <LocalizedField
                        id={fieldId('alt')}
                        label={t('landing_editor.field.image_alt')}
                        hint={t('landing_editor.field.image_alt_hint')}
                        value={hero.imageAlt}
                        onChange={(imageAlt) => onChange({ ...hero, imageAlt })}
                        isRequired
                        errors={fieldErrors('imageAlt', true)}
                        {...common}
                    />
                )}
                <LocalizedField
                    id={fieldId('button-label')}
                    label={t('landing_editor.field.button_label')}
                    value={hero.buttonLabel}
                    onChange={(buttonLabel) => onChange({ ...hero, buttonLabel })}
                    isRequired={hero.buttonUrl !== ''}
                    errors={fieldErrors('buttonLabel', true)}
                    {...common}
                />
                <div className="flex flex-col gap-1">
                    <label htmlFor={fieldId('button-url')} className="text-sm font-medium text-text">
                        {t('landing_editor.field.button_url')}
                    </label>
                    <input
                        id={fieldId('button-url')}
                        type="url"
                        inputMode="url"
                        placeholder="https://"
                        value={hero.buttonUrl}
                        onChange={(event) => onChange({ ...hero, buttonUrl: event.target.value })}
                        aria-describedby={fieldId('button-url-hint')}
                        aria-invalid={isUrlInvalid || urlErrors.length > 0 ? true : undefined}
                        className={FORM_CONTROL}
                    />
                    <p id={fieldId('button-url-hint')} className="text-sm text-text-muted">
                        {t('landing_editor.field.button_url_hint')}
                    </p>
                    {(isUrlInvalid || urlErrors.length > 0) && (
                        <p role="alert" className="text-sm font-medium text-error">
                            {urlErrors.length > 0 ? urlErrors.join(' ') : t('landing_editor.field.url_invalid')}
                        </p>
                    )}
                </div>
                <ChoiceField
                    id={fieldId('tone')}
                    label={t('landing_editor.field.tone')}
                    value={hero.tone}
                    options={toneOptions(t)}
                    onChange={(tone) => onChange({ ...hero, tone })}
                />
                <ChoiceField
                    id={fieldId('align')}
                    label={t('landing_editor.field.align')}
                    value={hero.align}
                    options={alignOptions(t)}
                    onChange={(align) => onChange({ ...hero, align })}
                />
            </>
        );
    }

    function renderFaq(faq: Extract<LandingBlockDraft, { type: 'faq' }>) {
        const updateItem = (position: number, patch: Partial<(typeof faq.items)[number]>): void =>
            onChange({ ...faq, items: faq.items.map((item, at) => (at === position ? { ...item, ...patch } : item)) });

        return (
            <>
                <LocalizedField
                    id={fieldId('title')}
                    label={t('landing_editor.field.section_title')}
                    hint={t('landing_editor.field.section_title_hint')}
                    value={faq.title}
                    onChange={(title) => onChange({ ...faq, title })}
                    errors={fieldErrors('title', true)}
                    {...common}
                />
                {faq.items.map((item, position) => (
                    <fieldset key={item.key} className={EDITOR_ITEM_FIELDSET}>
                        <legend className="px-1 text-sm font-semibold text-text">
                            {t('landing_editor.field.faq_item', { number: String(position + 1) })}
                        </legend>
                        <LocalizedField
                            id={fieldId(`q-${item.key}`)}
                            label={t('landing_editor.field.question')}
                            value={item.question}
                            onChange={(question) => updateItem(position, { question })}
                            isRequired
                            errors={fieldErrors(`items.${position}.question`, true)}
                            {...common}
                        />
                        <RichTextField
                            id={fieldId(`a-${item.key}`)}
                            label={t('landing_editor.field.answer')}
                            value={item.answer}
                            onChange={(answer) => updateItem(position, { answer })}
                            isRequired
                            errors={fieldErrors(`items.${position}.answer`, true)}
                            {...common}
                        />
                        <button
                            type="button"
                            onClick={() => onChange({ ...faq, items: faq.items.filter((_, at) => at !== position) })}
                            className={cn(BUTTON_SECONDARY, 'self-start')}
                        >
                            {t('landing_editor.field.remove_item', { number: String(position + 1) })}
                        </button>
                    </fieldset>
                ))}
                <button
                    type="button"
                    disabled={faq.items.length >= LANDING_LIMITS.faqItems}
                    onClick={() => onChange({ ...faq, items: [...faq.items, emptyFaqItem(locales)] })}
                    className={cn(BUTTON_SECONDARY, 'self-start')}
                >
                    {t('landing_editor.field.add_question')}
                </button>
            </>
        );
    }

    function renderGallery(gallery: Extract<LandingBlockDraft, { type: 'image_gallery' }>) {
        const addImage = (image: DraftImageRef | null): void => {
            if (image !== null) {
                onChange({ ...gallery, images: [...gallery.images, { key: newClientId(), image, alt: emptyText(locales) }] });
            }
        };

        return (
            <>
                <LocalizedField
                    id={fieldId('title')}
                    label={t('landing_editor.field.section_title')}
                    hint={t('landing_editor.field.section_title_hint')}
                    value={gallery.title}
                    onChange={(title) => onChange({ ...gallery, title })}
                    errors={fieldErrors('title', true)}
                    {...common}
                />
                {gallery.images.map((entry, position) => (
                    <fieldset key={entry.key} className={EDITOR_ITEM_FIELDSET}>
                        <legend className="px-1 text-sm font-semibold text-text">
                            {t('landing_editor.field.gallery_image', { number: String(position + 1) })}
                        </legend>
                        <img
                            src={entry.image.url}
                            alt=""
                            className="max-h-32 w-full rounded-md border border-border bg-bg object-contain"
                        />
                        <LocalizedField
                            id={fieldId(`alt-${entry.key}`)}
                            label={t('landing_editor.field.image_alt')}
                            hint={t('landing_editor.field.image_alt_hint')}
                            value={entry.alt}
                            onChange={(alt) =>
                                onChange({
                                    ...gallery,
                                    images: gallery.images.map((image, at) => (at === position ? { ...image, alt } : image)),
                                })
                            }
                            isRequired
                            errors={fieldErrors(`images.${position}.alt`, true)}
                            {...common}
                        />
                        <button
                            type="button"
                            onClick={() => onChange({ ...gallery, images: gallery.images.filter((_, at) => at !== position) })}
                            className={cn(BUTTON_SECONDARY, 'self-start')}
                        >
                            {t('landing_editor.field.remove_item', { number: String(position + 1) })}
                        </button>
                    </fieldset>
                ))}
                {gallery.images.length < LANDING_LIMITS.galleryImages ? (
                    <ImageField
                        key={gallery.images.length}
                        label={t('landing_editor.field.add_image')}
                        image={null}
                        onChange={addImage}
                        onUpload={onUpload}
                        t={t}
                    />
                ) : (
                    <p className="text-sm text-text-muted">
                        {t('landing_editor.field.gallery_full', { max: String(LANDING_LIMITS.galleryImages) })}
                    </p>
                )}
            </>
        );
    }

    function renderColumn(columns: Extract<LandingBlockDraft, { type: 'columns' }>, column: DraftColumn, position: 0 | 1 | number) {
        const replace = (next: DraftColumn): void => {
            const [first, second] = columns.columns;
            onChange({ ...columns, columns: position === 0 ? [next, second] : [first, next] });
        };

        return (
            <fieldset key={position} className={EDITOR_ITEM_FIELDSET}>
                <legend className="px-1 text-sm font-semibold text-text">
                    {t('landing_editor.field.column', { number: String(position + 1) })}
                </legend>
                <ChoiceField
                    id={fieldId(`kind-${position}`)}
                    label={t('landing_editor.field.column_content')}
                    value={column.kind}
                    options={[
                        { value: 'text', label: t('landing_editor.field.column_text') },
                        { value: 'image', label: t('landing_editor.field.column_image') },
                    ]}
                    onChange={(kind) =>
                        replace(kind === 'text' ? emptyTextColumn(locales) : { kind: 'image', image: null, alt: emptyText(locales) })
                    }
                />
                {column.kind === 'text' ? (
                    <RichTextField
                        id={fieldId(`html-${position}`)}
                        label={t('landing_editor.field.text')}
                        value={column.html}
                        onChange={(html) => replace({ kind: 'text', html })}
                        isRequired
                        errors={fieldErrors(`columns.${position}.html`, true)}
                        {...common}
                    />
                ) : (
                    <>
                        <ImageField
                            label={t('landing_editor.field.image')}
                            image={column.image}
                            onChange={(image) => replace({ ...column, image })}
                            onUpload={onUpload}
                            t={t}
                        />
                        {column.image !== null && (
                            <LocalizedField
                                id={fieldId(`alt-${position}`)}
                                label={t('landing_editor.field.image_alt')}
                                hint={t('landing_editor.field.image_alt_hint')}
                                value={column.alt}
                                onChange={(alt) => replace({ ...column, alt })}
                                isRequired
                                errors={fieldErrors(`columns.${position}.alt`, true)}
                                {...common}
                            />
                        )}
                    </>
                )}
            </fieldset>
        );
    }
}

function toneOptions(t: Translate): Array<{ value: LandingTone; label: string }> {
    return [
        { value: 'light', label: t('landing_editor.option.tone_light') },
        { value: 'accent', label: t('landing_editor.option.tone_accent') },
    ];
}

function alignOptions(t: Translate): Array<{ value: LandingAlign; label: string }> {
    return [
        { value: 'start', label: t('landing_editor.option.align_start') },
        { value: 'center', label: t('landing_editor.option.align_center') },
    ];
}

function widthOptions(t: Translate): Array<{ value: LandingWidth; label: string }> {
    return [
        { value: 'narrow', label: t('landing_editor.option.width_narrow') },
        { value: 'wide', label: t('landing_editor.option.width_wide') },
    ];
}

function sizeOptions(t: Translate): Array<{ value: LandingSpacerSize; label: string }> {
    return [
        { value: 'sm', label: t('landing_editor.option.size_sm') },
        { value: 'md', label: t('landing_editor.option.size_md') },
        { value: 'lg', label: t('landing_editor.option.size_lg') },
    ];
}
