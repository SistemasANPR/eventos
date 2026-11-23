import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { TextLink } from '@/components/atoms/TextLink';
import { PageBanner } from '@/components/molecules/PageBanner';
import { SubmissionForm } from '@/components/organisms/SubmissionForm';
import { PublicLayout } from '@/layouts/PublicLayout';
import { CALL_COLUMN, HEADING_LG, SECTION_CARD } from '@/lib/classes';
import { toSubmitFailure } from '@/lib/submit-failure';
import { createTranslate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { PublicCall } from '@/types/call';
import type { PublicOrganization } from '@/types/event';
import type { SharedProps } from '@/types/inertia';
import type { PublicSubmissionForm, SubmissionPayload, SubmitFailure } from '@/types/submission';

interface SubmitProps {
    call: PublicCall;
    event: { name: string; logoUrl?: string | null };
    organization: PublicOrganization;
    /** URL de la página de la convocatoria, con el idioma de esta página. */
    callUrl: string;
    /** URL a la que se envía el formulario. */
    submitUrl: string;
    /** `null` cuando la convocatoria no está abierta o no está lista: se muestra el estado sin formulario. */
    form: PublicSubmissionForm | null;
}

type SubmitPageProps = SharedProps & { errors: Record<string, string> };

/**
 * Página de envío de una propuesta: `/{organización}/events/{evento}/call/{convocatoria}/submit`.
 * Con la convocatoria abierta y lista muestra el formulario; si no, solo el estado (CA-006-55).
 * Los errores de validación llegan como props de Inertia; los demás rechazos (convocatoria cerrada,
 * formulario cambiado, límite de peticiones) llegan como respuesta HTTP y se muestran sin salir de la
 * página, de modo que lo escrito se conserva (CA-006-56).
 */
export default function Submit({ call, event, organization, callUrl, submitUrl, form }: SubmitProps) {
    const { translations, locale, errors } = usePage<SubmitPageProps>().props;
    const [failure, setFailure] = useState<SubmitFailure | null>(null);
    const [isProcessing, setIsProcessing] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);

    // Los textos del formulario (`forms.renderer`) viajan con el formulario, en el idioma de la página.
    const t = useMemo(() => createTranslate({ ...translations, ...(form?.translations ?? {}) }), [translations, form]);

    const handleSubmit = (payload: SubmissionPayload) => {
        setFailure(null);

        router.post(submitUrl, payload, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setIsProcessing(true),
            onFinish: () => setIsProcessing(false),
            onHttpException: (response) => {
                setFailure(toSubmitFailure(response));

                return false;
            },
            onNetworkError: () => {
                setFailure({ code: 'NETWORK', message: null, retryAfterSeconds: null });

                return false;
            },
        });
    };

    const handleRefresh = () => {
        router.reload({
            only: ['form'],
            onStart: () => setIsRefreshing(true),
            onFinish: () => setIsRefreshing(false),
            onSuccess: () => setFailure(null),
        });
    };

    const hint = form === null ? (call.state === 'open' ? t('submit.not_ready_hint') : t(`call.state_hint.${call.state}`)) : null;

    return (
        <PublicLayout organizationName={organization.name} isWide>
            <Head title={`${t('submit.title')} · ${call.title} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="submit-title" className={CALL_COLUMN}>
                <PageBanner
                    eyebrow={`${event.name} · ${call.title}`}
                    title={t('submit.title')}
                    titleId="submit-title"
                    logoUrl={event.logoUrl}
                    logoName={event.name}
                />

                {form !== null ? (
                    <SubmissionForm
                        form={form}
                        locale={locale}
                        t={t}
                        errors={errors}
                        failure={failure}
                        isProcessing={isProcessing}
                        isRefreshing={isRefreshing}
                        onSubmit={handleSubmit}
                        onRefresh={handleRefresh}
                    />
                ) : (
                    <section data-testid="submit-unavailable" className={cn(SECTION_CARD, 'space-y-2')}>
                        <h2 className={HEADING_LG}>
                            {call.state === 'open' ? t('submit.not_ready_title') : t(`call.state.${call.state}`)}
                        </h2>
                        <p className="max-w-prose text-base text-text-muted">{hint}</p>
                    </section>
                )}

                <div className="border-t border-border pt-4">
                    <TextLink href={callUrl} className="-ml-3">
                        {t('submit.back_to_call')}
                    </TextLink>
                </div>
            </article>
        </PublicLayout>
    );
}
