import type { CallState } from '@/types/call';

/** Cómo se pinta el punto del estado: verde (abierta), ámbar (próximamente) o gris (cerrada). */
export type LandingStatus = 'abierta' | 'proxima' | 'cerrada';

const DAY_MS = 86_400_000;

export function landingStatus(state: CallState): LandingStatus {
    return state === 'open' ? 'abierta' : state === 'scheduled' ? 'proxima' : 'cerrada';
}

export function statusLabel(state: CallState): string {
    return { open: 'Abierta', scheduled: 'Próximamente', paused: 'En pausa', closed: 'Cerrada' }[state];
}

/** Texto del botón principal: «Enviar propuesta» solo con la convocatoria abierta. */
export function ctaLabel(state: CallState): string {
    return state === 'open' ? 'Enviar propuesta' : state === 'scheduled' ? 'Abre pronto' : 'Convocatoria cerrada';
}

/** «30 de enero de 2027», en la zona horaria del evento. */
export function formatLandingDate(instant: string, timezone: string): string {
    return new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'long', year: 'numeric', timeZone: timezone }).format(
        new Date(instant),
    );
}

/** «a las 17:00 h (CDMX)», en la zona horaria del evento. */
export function formatLandingTime(instant: string, timezone: string, zoneLabel: string): string {
    const time = new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23', timeZone: timezone }).format(
        new Date(instant),
    );

    return `a las ${time} h (${zoneLabel})`;
}

/** Cuenta regresiva de la píldora de estado y de la barra del teléfono. */
export function countdownText(state: CallState, opensAt: string, closesAt: string, timezone: string, now: Date = new Date()): string {
    if (state === 'scheduled') {
        return `abre el ${formatLandingDate(opensAt, timezone)}`;
    }

    if (state === 'paused') {
        return 'recepción en pausa';
    }

    if (state === 'closed') {
        return 'ya no recibe propuestas';
    }

    const days = Math.ceil((new Date(closesAt).getTime() - now.getTime()) / DAY_MS);

    return days <= 1 ? 'cierra en menos de 24 horas' : `cierra en ${days} días`;
}
