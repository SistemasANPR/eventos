import { usePage } from '@inertiajs/react';

import { createTranslate, type Translate } from '@/lib/translate';
import type { SharedProps } from '@/types/inertia';

/** Función de traducción `t()` con los textos y el idioma que Laravel comparte con la página. */
export function useTranslation(): { t: Translate; locale: string } {
    const { translations, locale } = usePage<SharedProps>().props;

    return { t: createTranslate(translations), locale };
}
