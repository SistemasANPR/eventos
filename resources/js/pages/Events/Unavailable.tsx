import { Head } from '@inertiajs/react';

import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_TITLE } from '@/lib/classes';
import type { PublicOrganization } from '@/types/event';

interface UnavailableProps {
    /** Solo el nombre: ni fechas, ni programa, ni convocatorias (plan 032, CA-032-9). */
    event: { name: string };
    organization: PublicOrganization;
}

/**
 * Evento archivado (plan 032, H-032-2): el servidor responde 410 con esta página en lugar de un 404 mudo.
 * Es un aviso, no un error de la persona: no ofrece enlaces del evento porque ya no tiene páginas públicas.
 */
export default function Unavailable({ event, organization }: UnavailableProps) {
    const { t } = useTranslation();
    const title = t('unavailable.title');

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="unavailable-title" className="flex max-w-prose flex-col gap-4">
                <p className="text-sm font-semibold uppercase tracking-widest text-text-muted">{event.name}</p>
                <h1 id="unavailable-title" className={PAGE_TITLE}>
                    {title}
                </h1>
                <p className="text-lg text-text">{t('unavailable.body', { organization: organization.name })}</p>
                <p className="text-text-muted">{t('unavailable.hint')}</p>
            </article>
        </PublicLayout>
    );
}
