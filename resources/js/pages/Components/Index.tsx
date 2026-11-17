import { Head } from '@inertiajs/react';

import { demos } from '@/catalog/demos';
import { registry, type CatalogEntry } from '@/catalog/registry';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_TITLE } from '@/lib/classes';

/**
 * Catálogo interno de componentes: `/components` (design.md §1.4). Pinta cada entrada de
 * `catalog/registry.ts` con su demo. Herramienta del equipo: el servidor la restringe,
 * la responde con `X-Robots-Tag` y aquí además lleva `noindex`.
 */
export default function Index() {
    const { t } = useTranslation();

    return (
        <PublicLayout organizationName={t('catalog.title')}>
            <Head title={t('catalog.title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div className="flex flex-col gap-8">
                <header className="flex flex-col gap-3">
                    <h1 className={PAGE_TITLE}>{t('catalog.title')}</h1>
                    <p className="max-w-prose text-lg text-text-muted">{t('catalog.intro')}</p>
                </header>
                {registry.map((entry) => (
                    <CatalogSection key={entry.name} entry={entry} />
                ))}
            </div>
        </PublicLayout>
    );
}

function CatalogSection({ entry }: { entry: CatalogEntry }) {
    const { t } = useTranslation();
    const headingId = `catalog-${entry.name}`;
    const Demo = entry.preview === 'demo' ? demos[entry.name] : undefined;

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4 border-t border-border pt-6">
            <h2 id={headingId} className="text-2xl font-semibold text-text">
                {entry.name}
            </h2>
            <dl className="grid gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-1">
                    <dt className="text-sm font-medium text-text-muted">{t('catalog.level_label')}</dt>
                    <dd className="text-base text-text">{t(`catalog.level.${entry.level}`)}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-sm font-medium text-text-muted">{t('catalog.category_label')}</dt>
                    <dd className="text-base text-text">{entry.category}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-sm font-medium text-text-muted">{t('catalog.use_when')}</dt>
                    <dd className="text-base text-text">{entry.useWhen}</dd>
                </div>
                <div className="flex flex-col gap-1">
                    <dt className="text-sm font-medium text-text-muted">{t('catalog.avoid_for')}</dt>
                    <dd className="text-base text-text">{entry.avoidFor}</dd>
                </div>
            </dl>
            {Demo === undefined ? (
                <p className="text-sm text-text-muted">{t('catalog.no_demo')}</p>
            ) : (
                <div role="group" aria-label={t('catalog.demo')} data-testid={`demo-${entry.name}`} className="rounded-lg border border-border bg-surface p-4 sm:p-6">
                    <Demo />
                </div>
            )}
        </section>
    );
}
