import { ChoiceField } from '@/components/molecules/ChoiceField';
import { ImageField } from '@/components/molecules/ImageField';
import { LocalizedField } from '@/components/molecules/LocalizedField';
import { BUTTON_SECONDARY, EDITOR_ITEM_FIELDSET } from '@/lib/classes';
import { emptyStepItem, emptyTextItem, LANDING_LIMITS } from '@/lib/landing-blocks';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { LandingIconName } from '@/types/call-landing';
import type { CallLandingBlockDraft, LandingUploadResult } from '@/types/landing';

/** Iconos que se pueden elegir para un paso (los del diseño de la landing de convocatoria, salvo la flecha). */
const STEP_ICONS: LandingIconName[] = [
    'doc',
    'enviar',
    'lupa',
    'premio',
    'micro',
    'check',
    'calendario',
    'reloj',
    'pin',
    'impresora',
    'hoja',
    'personas',
    'plano',
    'chip',
    'ciudad',
    'monedas',
];

interface CallBlockFormProps {
    block: CallLandingBlockDraft;
    onChange: (block: CallLandingBlockDraft) => void;
    onUpload: (file: File) => Promise<LandingUploadResult>;
    locales: string[];
    /** Idioma de la pestaña activa y el base: los textos se editan en uno a la vez. */
    locale: string;
    defaultLocale: string;
    /** Errores del servidor para un campo (ruta relativa al bloque); `localized` añade el idioma activo. */
    fieldErrors: (path: string, localized: boolean) => string[];
    /** Identificador único de un campo dentro del panel. */
    fieldId: (name: string) => string;
    t: Translate;
}

/**
 * Formulario de ajustes de las secciones propias de la landing de una convocatoria (plan 033): portada, fechas, ejes,
 * requisitos, pasos y anuncios. Todos los textos son opcionales: vacíos, la sección usa el dato de la convocatoria o
 * un texto de relleno. Usar en: `BlockSettingsPanel`.
 */
export function CallBlockForm({ block, onChange, onUpload, locales, locale, defaultLocale, fieldErrors, fieldId, t }: CallBlockFormProps) {
    const common = { locale, defaultLocale, t };
    const field = (name: string, value: Record<string, string>, set: (next: Record<string, string>) => void, extra = {}) => (
        <LocalizedField
            id={fieldId(name)}
            label={t(`landing_editor.call_field.${name}`)}
            hint={t(`landing_editor.call_field.${name}_hint`)}
            value={value}
            onChange={set}
            errors={fieldErrors(name, true)}
            {...extra}
            {...common}
        />
    );

    switch (block.type) {
        case 'call_hero':
            return (
                <>
                    {field('title', block.title, (title) => onChange({ ...block, title }))}
                    {field('eventLine', block.eventLine, (eventLine) => onChange({ ...block, eventLine }))}
                    {field('lede', block.lede, (lede) => onChange({ ...block, lede }), { isMultiline: true })}
                    {field('notice', block.notice, (notice) => onChange({ ...block, notice }))}
                    <ImageField
                        label={t('landing_editor.call_field.image')}
                        image={block.image}
                        onChange={(image) => onChange({ ...block, image })}
                        onUpload={onUpload}
                        errors={fieldErrors('image', false)}
                        t={t}
                    />
                    {block.image !== null &&
                        field('imageAlt', block.imageAlt, (imageAlt) => onChange({ ...block, imageAlt }), { isRequired: true })}
                </>
            );
        case 'call_dates':
            return (
                <>
                    <p className="text-base text-text-muted">{t('landing_editor.settings.call_dates_note')}</p>
                    {field('timezoneLabel', block.timezoneLabel, (timezoneLabel) => onChange({ ...block, timezoneLabel }))}
                    {field('resultsLabel', block.resultsLabel, (resultsLabel) => onChange({ ...block, resultsLabel }))}
                    {field('venueName', block.venueName, (venueName) => onChange({ ...block, venueName }))}
                    {field('venueNote', block.venueNote, (venueNote) => onChange({ ...block, venueNote }))}
                </>
            );
        case 'call_axes':
            return (
                <>
                    {field('title', block.title, (title) => onChange({ ...block, title }))}
                    {field('intro', block.intro, (intro) => onChange({ ...block, intro }), { isMultiline: true })}
                    <p className="text-base text-text-muted">{t('landing_editor.settings.call_axes_note')}</p>
                </>
            );
        case 'call_requirements':
            return (
                <>
                    {field('title', block.title, (title) => onChange({ ...block, title }))}
                    {field('intro', block.intro, (intro) => onChange({ ...block, intro }), { isMultiline: true })}
                    <p className="text-base text-text-muted">{t('landing_editor.settings.call_requirements_note')}</p>
                    {block.items.map((item, position) => (
                        <fieldset key={item.key} className={EDITOR_ITEM_FIELDSET}>
                            <legend className="px-1 text-sm font-semibold text-text">
                                {t('landing_editor.call_field.requirement', { number: String(position + 1) })}
                            </legend>
                            <LocalizedField
                                id={fieldId(`req-${item.key}`)}
                                label={t('landing_editor.call_field.requirement_text')}
                                hint={t('landing_editor.call_field.requirement_text_hint')}
                                value={item.text}
                                onChange={(text) =>
                                    onChange({
                                        ...block,
                                        items: block.items.map((entry, at) => (at === position ? { ...entry, text } : entry)),
                                    })
                                }
                                isMultiline
                                isRequired
                                errors={fieldErrors(`items.${position}.text`, true)}
                                {...common}
                            />
                            <button
                                type="button"
                                onClick={() => onChange({ ...block, items: block.items.filter((_, at) => at !== position) })}
                                className={cn(BUTTON_SECONDARY, 'self-start')}
                            >
                                {t('landing_editor.field.remove_item', { number: String(position + 1) })}
                            </button>
                        </fieldset>
                    ))}
                    <button
                        type="button"
                        disabled={block.items.length >= LANDING_LIMITS.callItems}
                        onClick={() => onChange({ ...block, items: [...block.items, emptyTextItem(locales)] })}
                        className={cn(BUTTON_SECONDARY, 'self-start')}
                    >
                        {t('landing_editor.call_field.add_requirement')}
                    </button>
                    {field('buttonLabel', block.buttonLabel, (buttonLabel) => onChange({ ...block, buttonLabel }))}
                </>
            );
        case 'call_steps':
            return (
                <>
                    {field('title', block.title, (title) => onChange({ ...block, title }))}
                    {block.items.map((item, position) => {
                        const update = (patch: Partial<typeof item>): void =>
                            onChange({
                                ...block,
                                items: block.items.map((entry, at) => (at === position ? { ...entry, ...patch } : entry)),
                            });

                        return (
                            <fieldset key={item.key} className={EDITOR_ITEM_FIELDSET}>
                                <legend className="px-1 text-sm font-semibold text-text">
                                    {t('landing_editor.call_field.step', { number: String(position + 1) })}
                                </legend>
                                <ChoiceField
                                    id={fieldId(`icon-${item.key}`)}
                                    label={t('landing_editor.call_field.step_icon')}
                                    value={item.icon}
                                    options={STEP_ICONS.map((icon) => ({ value: icon, label: t(`landing_editor.icon.${icon}`) }))}
                                    onChange={(icon) => update({ icon })}
                                />
                                <LocalizedField
                                    id={fieldId(`step-title-${item.key}`)}
                                    label={t('landing_editor.call_field.step_title')}
                                    value={item.title}
                                    onChange={(title) => update({ title })}
                                    isRequired
                                    errors={fieldErrors(`items.${position}.title`, true)}
                                    {...common}
                                />
                                <LocalizedField
                                    id={fieldId(`step-text-${item.key}`)}
                                    label={t('landing_editor.call_field.step_text')}
                                    value={item.text}
                                    onChange={(text) => update({ text })}
                                    isMultiline
                                    isRequired
                                    errors={fieldErrors(`items.${position}.text`, true)}
                                    {...common}
                                />
                                <button
                                    type="button"
                                    onClick={() => onChange({ ...block, items: block.items.filter((_, at) => at !== position) })}
                                    className={cn(BUTTON_SECONDARY, 'self-start')}
                                >
                                    {t('landing_editor.field.remove_item', { number: String(position + 1) })}
                                </button>
                            </fieldset>
                        );
                    })}
                    <button
                        type="button"
                        disabled={block.items.length >= LANDING_LIMITS.callItems}
                        onClick={() => onChange({ ...block, items: [...block.items, emptyStepItem(locales)] })}
                        className={cn(BUTTON_SECONDARY, 'self-start')}
                    >
                        {t('landing_editor.call_field.add_step')}
                    </button>
                </>
            );
        case 'call_promos':
            return (
                <>
                    {field('buttonLabel', block.buttonLabel, (buttonLabel) => onChange({ ...block, buttonLabel }))}
                    <p className="text-base text-text-muted">{t('landing_editor.settings.call_promos_note')}</p>
                </>
            );
    }
}
