import { EventFact } from '@/components/molecules/EventFact';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';

/** Demo de `EventFact`: se usa dentro de un `<dl>`. */
export function EventFactDemo() {
    const { t } = useTranslation();

    return (
        <dl>
            <EventFact label={t('catalog.demo_data.fact_label')}>{t('catalog.demo_data.fact_value')}</EventFact>
        </dl>
    );
}

/** Demo de `LocaleSwitcher`: una página disponible en tres idiomas. */
export function LocaleSwitcherDemo() {
    const { locale } = useTranslation();

    return <LocaleSwitcher locales={['es', 'en', 'pt']} currentLocale={locale} />;
}
