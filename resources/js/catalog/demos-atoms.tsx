import { RichText } from '@/components/atoms/RichText';
import { TextLink } from '@/components/atoms/TextLink';
import { useTranslation } from '@/hooks/useTranslation';

/** Demo de `RichText`: formato permitido y un enlace http(s); datos ficticios. */
export function RichTextDemo() {
    const { t } = useTranslation();

    return <RichText html={t('catalog.demo_data.rich_text')} />;
}

/** Demo de `TextLink`: enlace normal y enlace de la opción activa. */
export function TextLinkDemo() {
    const { t } = useTranslation();

    return (
        <div className="flex flex-wrap gap-2">
            <TextLink href="#">{t('catalog.demo_data.link')}</TextLink>
            <TextLink href="#" isActive>
                {t('catalog.demo_data.link_active')}
            </TextLink>
        </div>
    );
}
