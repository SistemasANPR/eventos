import type { Translations } from '@/types/inertia';

/** Tipos de campo que el servidor acepta (`App\Enums\FieldType`). */
export type FieldType =
    | 'short_text'
    | 'long_text'
    | 'number'
    | 'single_choice'
    | 'multiple_choice'
    | 'checkbox'
    | 'date'
    | 'country'
    | 'file';

/** Texto por idioma. PHP serializa un arreglo vacío como `[]`, de ahí la unión. */
export type LocalizedText = Record<string, string> | readonly string[];

export interface FormFieldOption {
    value: string;
    label: LocalizedText;
}

export interface FormFieldConfig {
    minLength?: number;
    maxLength?: number;
    min?: number;
    max?: number;
    maxSelections?: number;
    options?: FormFieldOption[];
    accept?: string[];
    maxFiles?: number;
}

/** Un campo de la definición guardada de un formulario (docs/plans 005 §4). */
export interface FormField {
    key: string;
    type: FieldType;
    required: boolean;
    position: number;
    identifiesAuthor: boolean;
    label: LocalizedText;
    help: LocalizedText;
    /** `[]` cuando el tipo no tiene configuración (PHP). */
    config: FormFieldConfig | readonly never[];
    retiredAt: string | null;
}

/** Datos que el panel entrega a la vista previa (`App\Support\Forms\FormPreviewProps`). */
export interface FormPreviewProps {
    fields: FormField[];
    locale: string;
    defaultLocale: string;
    translations: Translations;
}
