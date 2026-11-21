import { Head } from '@inertiajs/react';

import { EventLogo } from '@/components/atoms/EventLogo';
import { TextLink } from '@/components/atoms/TextLink';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_TITLE } from '@/lib/classes';
import type { PublicOrganization } from '@/types/event';

interface SubmittedProps {
    call: { title: string };
    event: { name: string; logoUrl?: string | null };
    organization: PublicOrganization;
    callUrl: string;
    eventUrl: string;
}

/**
 * Confirmación del envío (CA-006-57): sin datos personales ni identificadores. El detalle de lo
 * enviado llega por correo a la persona principal.
 */
export default function Submitted({ call, event, organization, callUrl, eventUrl }: SubmittedProps) {
    const { t } = useTranslation();

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${t('submitted.title')} · ${call.title} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="submitted-title" className="flex flex-col gap-6">
                <EventLogo src={event.logoUrl} name={event.name} />
                <p className="text-base font-medium text-text-muted">{event.name}</p>
                <h1 id="submitted-title" className={PAGE_TITLE}>
                    {t('submitted.title')}
                </h1>
                <p role="status" className="max-w-prose text-lg text-text">
                    {t('submitted.body', { call: call.title })}
                </p>
                <div className="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:gap-6">
                    <TextLink href={callUrl} className="-ml-3">
                        {t('submitted.back_to_call')}
                    </TextLink>
                    <TextLink href={eventUrl}>{t('submitted.back_to_event')}</TextLink>
                </div>
            </article>
        </PublicLayout>
    );
}
