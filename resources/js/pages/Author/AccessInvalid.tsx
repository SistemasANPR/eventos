import { Head } from '@inertiajs/react';

import { TextLink } from '@/components/atoms/TextLink';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_TITLE } from '@/lib/classes';
import type { PublicOrganization } from '@/types/event';

interface AccessInvalidProps {
    event: { name: string };
    organization: PublicOrganization;
    /** Página para pedir un enlace nuevo. */
    requestLinkUrl: string;
}

/**
 * Enlace de acceso que no sirve. Un solo mensaje para todas las causas (usado, caducado, alterado,
 * inexistente, de otra organización): no se revela cuál fue.
 */
export default function AccessInvalid({ event, organization, requestLinkUrl }: AccessInvalidProps) {
    const { t } = useTranslation();
    const title = t('author.invalid.title');

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="invalid-title" className="flex max-w-prose flex-col gap-4">
                <h1 id="invalid-title" className={PAGE_TITLE}>
                    {title}
                </h1>
                <p role="alert" className="text-lg text-text">
                    {t('author.invalid.body')}
                </p>
                <TextLink href={requestLinkUrl} className="-ml-3 self-start">
                    {t('author.invalid.request_link')}
                </TextLink>
            </article>
        </PublicLayout>
    );
}
