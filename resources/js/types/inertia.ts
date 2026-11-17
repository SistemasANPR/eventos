/** Textos de `lang/{idioma}/public.php`: grupos anidados de cadenas. */
export type Translations = { [key: string]: string | Translations };

/** Props que Laravel comparte con todas las páginas públicas (HandleInertiaRequests). */
export interface SharedProps {
    locale: string;
    translations: Translations;
    [key: string]: unknown;
}
