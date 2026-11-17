import type { Translations } from '@/types/inertia';

export type Translate = (key: string, replacements?: Record<string, string>) => string;

/**
 * Crea la función de traducción sobre los textos de `lang/`: `t('event.when')`
 * busca por ruta con puntos y sustituye `:nombre`. Una clave inexistente
 * devuelve la propia clave, para que el hueco se vea y no rompa la página.
 */
export function createTranslate(translations: Translations): Translate {
    return (key, replacements = {}) => {
        let node: string | Translations | undefined = translations;

        for (const segment of key.split('.')) {
            node = typeof node === 'object' ? node[segment] : undefined;
        }

        if (typeof node !== 'string') {
            return key;
        }

        return Object.entries(replacements).reduce(
            (text, [name, value]) => text.replaceAll(`:${name}`, value),
            node,
        );
    };
}
