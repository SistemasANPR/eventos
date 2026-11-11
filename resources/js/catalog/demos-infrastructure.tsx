import { SkipToContent } from '@/components/infrastructure/SkipToContent';
import { useTranslation } from '@/hooks/useTranslation';

/** Demo de `SkipToContent`: invisible hasta recibir el foco del teclado, así que lleva una indicación. */
export function SkipToContentDemo() {
    const { t } = useTranslation();

    return (
        <div>
            <SkipToContent />
            <p className="text-base text-text-muted">{t('catalog.demo_data.skip_hint')}</p>
        </div>
    );
}
