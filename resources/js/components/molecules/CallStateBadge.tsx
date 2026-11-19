import type { Translate } from '@/lib/translate';
import type { CallState } from '@/types/call';

interface CallStateBadgeProps {
    state: CallState;
    t: Translate;
}

/** Colores (par texto/fondo de los semánticos) y marca de cada estado: el estado no depende solo del color. */
const STATE_STYLE: Record<CallState, { classes: string; mark: string }> = {
    scheduled: { classes: 'bg-info-bg text-info', mark: '◷' },
    open: { classes: 'bg-success-bg text-success', mark: '●' },
    paused: { classes: 'bg-warning-bg text-warning', mark: '❚❚' },
    closed: { classes: 'bg-error-bg text-error', mark: '■' },
};

/**
 * Píldora con el estado público de una convocatoria: color, marca y texto.
 * Usar donde se muestre el estado de una convocatoria; no para otros estados.
 */
export function CallStateBadge({ state, t }: CallStateBadgeProps) {
    const style = STATE_STYLE[state];

    return (
        <span
            data-testid="call-state"
            className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-semibold ${style.classes}`}
        >
            <span aria-hidden="true">{style.mark}</span>
            {t(`call.state.${state}`)}
        </span>
    );
}
