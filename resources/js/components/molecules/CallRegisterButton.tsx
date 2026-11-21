import { BUTTON_PRIMARY, FOCUS_RING } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { CallState } from '@/types/call';

interface CallRegisterButtonProps {
    /** URL del formulario de envío; `null` si la convocatoria no está abierta. */
    registerUrl: string | null;
    state: CallState;
    t: Translate;
}

/**
 * Botón de registro de una convocatoria. Abierta: enlace al formulario. Programada, pausada o
 * cerrada: botón BLOQUEADO (deshabilitado, sin enlace) con el estado dicho también a los
 * lectores de pantalla (plan 022, DA-17). El servidor rechaza el envío aparte (`CALL_NOT_OPEN`).
 */
export function CallRegisterButton({ registerUrl, state, t }: CallRegisterButtonProps) {
    if (registerUrl !== null && state === 'open') {
        return (
            <a href={registerUrl} className={cn(BUTTON_PRIMARY, FOCUS_RING)}>
                {t('submit.send_action')}
            </a>
        );
    }

    return (
        <button
            type="button"
            disabled
            aria-disabled="true"
            className="inline-flex min-h-12 cursor-not-allowed items-center justify-center rounded-full border border-border bg-bg px-8 text-base font-extrabold uppercase tracking-wide text-text-muted"
        >
            {t('submit.send_action')}
            <span className="sr-only">{t('landing.register_unavailable', { state: t(`call.state.${state}`) })}</span>
        </button>
    );
}
