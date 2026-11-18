import { TextLink } from '@/components/atoms/TextLink';
import { useTranslation } from '@/hooks/useTranslation';

interface LocaleSwitcherProps {
    /** Idiomas en que está disponible la página. */
    locales: string[];
    /** Idioma que se está viendo. */
    currentLocale: string;
}

/**
 * Enlaces para ver la página en otro de sus idiomas (`?lang=xx`).
 * No se muestra si la página solo está disponible en uno.
 */
export function LocaleSwitcher({ locales, currentLocale }: LocaleSwitcherProps) {
    const { t } = useTranslation();

    if (locales.length < 2) {
        return null;
    }

    return (
        <nav aria-label={t('event.language')} className="flex flex-wrap gap-1">
            {locales.map((locale) => (
                <TextLink
                    key={locale}
                    href={`?lang=${locale}`}
                    hrefLang={locale}
                    lang={locale}
                    isActive={locale === currentLocale}
                    aria-label={t('event.language_option', { language: t(`languages.${locale}`) })}
                >
                    {t(`languages.${locale}`)}
                </TextLink>
            ))}
        </nav>
    );
}
