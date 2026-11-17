import type { FormField, FormFieldConfig, LocalizedText } from '@/types/form';

/**
 * Texto en el idioma pedido o, si falta o está vacío, en el idioma base (CA-005-12).
 * Sin ninguno de los dos devuelve cadena vacía: el servidor exige el texto base.
 */
export function pickText(texts: LocalizedText, locale: string, defaultLocale: string): string {
    if (Array.isArray(texts)) {
        return '';
    }

    const byLocale = texts as Record<string, string>;
    const requested = byLocale[locale];

    if (typeof requested === 'string' && requested.trim() !== '') {
        return requested;
    }

    return byLocale[defaultLocale] ?? '';
}

/** La configuración de un campo; PHP entrega `[]` cuando no tiene. */
export function configOf(field: FormField): FormFieldConfig {
    return Array.isArray(field.config) ? {} : (field.config as FormFieldConfig);
}

/** Campos vigentes (no retirados) en el orden de su posición. */
export function activeFields(fields: readonly FormField[]): FormField[] {
    return fields.filter((field) => field.retiredAt === null).sort((a, b) => a.position - b.position);
}
