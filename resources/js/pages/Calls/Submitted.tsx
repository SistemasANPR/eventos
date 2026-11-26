import { Head } from '@inertiajs/react';

import { HexagonBadge } from '@/components/atoms/HexagonBadge';
import { PageBanner } from '@/components/molecules/PageBanner';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_PRIMARY, BUTTON_SECONDARY, CALL_COLUMN, FOCUS_RING } from '@/lib/classes';
import { formatEventInstant } from '@/lib/date-format';
import { cn } from '@/lib/utils';
import type { PublicOrganization, TimeFormat } from '@/types/event';

interface SubmittedProps {
    call: { title: string };
    event: { name: string; logoUrl?: string | null };
    organization: PublicOrganization;
    callUrl: string;
    eventUrl: string;
    /** Propuesta del autor (plan 034): sin token; si la sesión caducó, esa ruta pide un enlace de acceso. */
    authorUrl: string;
    /** Cierre de la convocatoria (ISO 8601, UTC) mientras siga abierta; hasta entonces puede editar. */
    editDeadline: string | null;
    timezone: string;
    timeFormat: TimeFormat;
}

/**
 * Confirmación del envío (CA-006-57): sin datos personales ni identificadores. El detalle de lo
 * enviado llega por correo a la persona principal. Ofrece entrar a la propuesta y dice hasta cuándo se puede
 * editar (plan 034, CA-034-6).
 */
export default function Submitted({ call, event, organization, callUrl, eventUrl, authorUrl, editDeadline, timezone, timeFormat }: SubmittedProps) {
    const { t, locale } = useTranslation();

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
                {editDeadline !== null && (
                    <p className="text-center text-base text-text-muted">
                        {t('submitted.edit_until', { date: formatEventInstant(editDeadline, timezone, locale, timeFormat) })}
                    </p>
                )}
                <div className="flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:gap-4">
                    <a href={authorUrl} className={cn(BUTTON_PRIMARY, FOCUS_RING)}>
                        {t('submitted.edit_action')}
                    </a>
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
