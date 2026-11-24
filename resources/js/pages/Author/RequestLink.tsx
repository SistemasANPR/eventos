import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useCallback, useState } from 'react';

import { TurnstileWidget } from '@/components/infrastructure/TurnstileWidget';
import { TextLink } from '@/components/atoms/TextLink';
import { LocaleSwitcher } from '@/components/molecules/LocaleSwitcher';
import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_PRIMARY, FORM_CONTROL, PAGE_FOOTER, PAGE_TITLE } from '@/lib/classes';
import type { AccessLinkPageProps } from '@/types/author';

/**
 * «Pedir mi enlace de acceso»: `/{organización}/events/{evento}/my-submission/request-link`. La respuesta es la
 * misma exista o no una propuesta con ese correo (no se revela quién tiene una). Sin sesión ni datos de
 * propuestas: solo el correo y la verificación anti-robots.
 */
export default function RequestLink({
    event,
    organization,
    turnstileSiteKey,
    linkRequested,
    tooManyRequests,
    maxLinks,
    expiresInMinutes,
    eventUrl,
}: AccessLinkPageProps) {
    const { t } = useTranslation();
    const { url } = usePage();
    const form = useForm({ email: '', turnstile_token: '' });
    const [turnstileToken, setTurnstileToken] = useState('');
    const [hasTurnstileFailed, setHasTurnstileFailed] = useState(false);
    // El token de Turnstile vale para un solo envío: tras cada intento se pide uno nuevo.
    const [resetCount, setResetCount] = useState(0);

    const handleToken = useCallback((token: string | null) => setTurnstileToken(token ?? ''), []);
    const handleLoadError = useCallback(() => setHasTurnstileFailed(true), []);

    const handleSubmit = (submitEvent: FormEvent<HTMLFormElement>) => {
        submitEvent.preventDefault();
        form.transform((data) => ({ ...data, turnstile_token: turnstileToken }));
        form.post(url, {
            preserveScroll: true,
            onFinish: () => {
                setTurnstileToken('');
                setResetCount((current) => current + 1);
            },
        });
    };

    const emailError = form.errors.email;
    const turnstileError = form.errors.turnstile_token;
    const title = t('author.request.title');
    const hasSiteKey = turnstileSiteKey !== '';

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="request-title" className="flex flex-col gap-6">
                <header className="flex flex-col gap-2">
                    <h1 id="request-title" className={PAGE_TITLE}>
                        {title}
                    </h1>
                    <p className="text-lg text-text">{event.name}</p>
                    <p className="max-w-prose text-base text-text-muted">{t('author.request.intro')}</p>
                </header>

                {linkRequested && (
                    <section
                        role="status"
                        aria-labelledby="request-sent-title"
                        className="rounded-lg border border-border bg-success-bg p-4"
                    >
                        <h2 id="request-sent-title" className="text-lg font-semibold text-success">
                            {t('author.request.sent_title')}
                        </h2>
                        <p className="text-base text-text">
                            {t('author.request.sent_body', { max: String(maxLinks), minutes: String(expiresInMinutes) })}
                        </p>
                    </section>
                )}

                {tooManyRequests && (
                    <section
                        role="alert"
                        aria-labelledby="request-limit-title"
                        className="rounded-lg border border-border bg-warning-bg p-4"
                    >
                        <h2 id="request-limit-title" className="text-lg font-semibold text-warning">
                            {t('author.request.too_many_title')}
                        </h2>
                        <p className="text-base text-text">{t('author.request.too_many_body')}</p>
                    </section>
                )}

                <form onSubmit={handleSubmit} noValidate className="flex max-w-prose flex-col gap-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="request-email" className="text-base font-medium text-text">
                            {t('author.request.email_label')}
                        </label>
                        <input
                            id="request-email"
                            name="email"
                            type="email"
                            autoComplete="email"
                            required
                            value={form.data.email}
                            onChange={(changeEvent) => form.setData('email', changeEvent.target.value)}
                            aria-invalid={emailError !== undefined ? true : undefined}
                            aria-describedby={emailError !== undefined ? 'request-email-error' : undefined}
                            className={FORM_CONTROL}
                        />
                        {emailError !== undefined && (
                            <p id="request-email-error" role="alert" className="text-base text-error">
                                {emailError}
                            </p>
                        )}
                    </div>

                    {hasSiteKey ? (
                        <TurnstileWidget
                            siteKey={turnstileSiteKey}
                            locale={event.locale}
                            onToken={handleToken}
                            onLoadError={handleLoadError}
                            resetCount={resetCount}
                        />
                    ) : (
                        <p role="alert" className="text-base text-error">
                            {t('author.request.turnstile_missing')}
                        </p>
                    )}
                    {hasTurnstileFailed && (
                        <p role="alert" className="text-base text-error">
                            {t('author.request.turnstile_failed')}
                        </p>
                    )}
                    {turnstileError !== undefined && (
                        <p role="alert" className="text-base text-error">
                            {turnstileError}
                        </p>
                    )}

                    <button type="submit" disabled={form.processing} className={`${BUTTON_PRIMARY} self-start`}>
                        {form.processing ? t('author.request.sending') : t('author.request.submit')}
                    </button>
                </form>

                <div className={PAGE_FOOTER}>
                    <TextLink href={eventUrl} className="-ml-3">
                        {t('author.request.back_to_event')}
                    </TextLink>
                    <LocaleSwitcher locales={event.locales} currentLocale={event.locale} />
                </div>
            </article>
        </PublicLayout>
    );
}
