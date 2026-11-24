import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { useTranslation } from '@/hooks/useTranslation';
import { PublicLayout } from '@/layouts/PublicLayout';
import { BUTTON_PRIMARY, PAGE_TITLE } from '@/lib/classes';
import type { PublicOrganization } from '@/types/event';

interface ConfirmAccessProps {
    event: { name: string };
    organization: PublicOrganization;
    /** Destino del POST que consume el enlace (lo arma el servidor). */
    consumeUrl: string;
    /** Minutos que dura la sesión del autor una vez abierta. */
    expiresInMinutes: number;
}

/**
 * Confirmación del enlace de acceso: abrir el enlace solo muestra esta página, sin efectos. El enlace de un
 * solo uso se gasta únicamente con el botón (POST), así un escáner de correo no lo consume.
 */
export default function ConfirmAccess({ event, organization, consumeUrl, expiresInMinutes }: ConfirmAccessProps) {
    const { t } = useTranslation();
    const form = useForm({});
    const title = t('author.confirm.title');

    const handleSubmit = (submitEvent: FormEvent<HTMLFormElement>) => {
        submitEvent.preventDefault();
        form.post(consumeUrl);
    };

    return (
        <PublicLayout organizationName={organization.name}>
            <Head title={`${title} · ${event.name} · ${organization.name}`}>
                <meta name="robots" content="noindex" />
            </Head>
            <article aria-labelledby="confirm-title" className="flex max-w-prose flex-col gap-6">
                <h1 id="confirm-title" className={PAGE_TITLE}>
                    {title}
                </h1>
                <p className="text-lg text-text">{t('author.confirm.body', { event: event.name, minutes: String(expiresInMinutes) })}</p>
                <form onSubmit={handleSubmit}>
                    <button type="submit" disabled={form.processing} className={BUTTON_PRIMARY}>
                        {form.processing ? t('author.confirm.opening') : t('author.confirm.action')}
                    </button>
                </form>
            </article>
        </PublicLayout>
    );
}
