import { Head } from '@inertiajs/react';

import { HexagonBadge } from '@/components/atoms/HexagonBadge';
import { PageBanner } from '@/components/molecules/PageBanner';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_SECONDARY, CALL_COLUMN, FOCUS_RING } from '@/lib/classes';
import { cn } from '@/lib/utils';
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
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={`${t('submitted.title')} · ${call.title} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="submitted-title" className={CALL_COLUMN}>
                <div className="flex flex-col">
                    <PageBanner
                        eyebrow={event.name}
                        title={t('submitted.title')}
                        titleId="submitted-title"
                        logoUrl={event.logoUrl}
                        logoName={event.name}
                        badge={<HexagonBadge className="h-24 w-24 sm:h-28 sm:w-28" />}
                        align="center"
                        hasOverlap
                    />
                    <p
                        role="status"
                        className="relative z-10 mx-4 -mt-12 rounded-3xl border border-border border-t-4 border-t-cta bg-surface p-6 text-center font-display text-xl font-bold leading-snug text-text shadow-lg sm:mx-12 sm:p-8 sm:text-2xl"
                    >
                        {t('submitted.body', { call: call.title })}
                    </p>
                </div>
                <div className="flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:gap-4">
                    <a href={callUrl} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                        {t('submitted.back_to_call')}
                    </a>
                    <a href={eventUrl} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                        {t('submitted.back_to_event')}
                    </a>
                </div>
            </article>
        </PublicLayout>
    );
}
