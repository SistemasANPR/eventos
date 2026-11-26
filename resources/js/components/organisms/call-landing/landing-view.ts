import type { LandingView } from '@/components/organisms/call-landing/LandingParts';
import { countdownText, ctaLabel, formatLandingDate, formatLandingTime, landingStatus, statusLabel } from '@/lib/call-landing-view';
import type { PublicCall } from '@/types/call';

/** Los dos textos que cambian lo que muestran las fechas: la etiqueta de la zona horaria y la ficha de resultados. */
interface ViewTexts {
    timezoneLabel: string;
    /** Texto de la ficha de resultados; sin él, se usa la fecha de resultados de la convocatoria (si la hay). */
    resultsLabel?: string;
}

/** Lo que se calcula con los datos reales de la convocatoria: estado, fechas y botón al formulario. */
export function buildLandingView(call: PublicCall, texts: ViewTexts, submitUrl: string | null): LandingView {
    return {
        status: landingStatus(call.state),
        statusText: statusLabel(call.state),
        countdown: countdownText(call.state, call.opensAt, call.closesAt, call.timezone),
        opensOn: formatLandingDate(call.opensAt, call.timezone),
        closesOn: formatLandingDate(call.closesAt, call.timezone),
        closesAt: formatLandingTime(call.closesAt, call.timezone, texts.timezoneLabel),
        resultsOn: texts.resultsLabel ?? (call.resultsAt === undefined ? undefined : formatLandingDate(call.resultsAt, call.timezone)),
        submitUrl,
        ctaLabel: ctaLabel(call.state),
    };
}
