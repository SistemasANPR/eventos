import { Head } from '@inertiajs/react';

import { RichText } from '@/components/atoms/RichText';
import { TextLink } from '@/components/atoms/TextLink';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { PAGE_TITLE } from '@/lib/classes';
import { formatPublishedAt } from '@/lib/date-format';
import type { PrivacyNotice, PrivacyPageEvent, PublicOrganization } from '@/types/event';

interface PrivacyProps {
    event: PrivacyPageEvent;
    organization: PublicOrganization;
    notice: PrivacyNotice;
    /** URL de la página del evento, con el idioma de esta página: la entrega el servidor. */
    eventUrl: string;
}

/** Aviso de privacidad vigente de un evento: `/{organización}/events/{evento}/privacy`. */
export default function Privacy({ event, organization, notice, eventUrl }: PrivacyProps) {
    const { t } = useTranslation();

    const isInBaseLanguage = notice.locale !== event.locale;
    const title = t('privacy.title');

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`} />
            <article aria-labelledby="privacy-title" className="flex flex-col gap-6">
                <header className="flex flex-col gap-2">
                    <h1 id="privacy-title" className={PAGE_TITLE}>
                        {title}
                    </h1>
                    <p className="text-lg text-text">{event.name}</p>
                    <p className="flex flex-wrap gap-x-3 text-sm text-text-muted">
                        <span>{t('privacy.version', { version: String(notice.version) })}</span>
                        <span>{t('privacy.published_on', { date: formatPublishedAt(notice.publishedAt, event.locale) })}</span>
                    </p>
                    {isInBaseLanguage && (
                        <p className="text-sm text-text-muted">{t('privacy.shown_in_base_language')}</p>
                    )}
                </header>

                <div lang={notice.locale} className="max-w-prose">
                    <RichText html={notice.body} />
                </div>

                <div className="flex flex-col gap-2 border-t border-border pt-4 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                    <TextLink href={eventUrl} className="-ml-3">
                        {t('privacy.back_to_event')}
                    </TextLink>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </article>
        </PublicLayout>
    );
}
