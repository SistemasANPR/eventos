import type { FormField } from '@/types/form';
import type { Translations } from '@/types/inertia';

/** Eje elegible en el formulario público. */
export interface SubmissionTrack {
    id: string;
    name: string;
}

/** Formulario público de una convocatoria abierta: coincide con `App\Actions\Submissions\PrepareSubmissionForm`. */
export interface PublicSubmissionForm {
    /** Versión vigente de la definición; el servidor responde 409 si el envío trae otra. */
    version: number;
    fields: FormField[];
    defaultLocale: string;
    /** Textos de `forms.renderer` en el idioma de la página. */
    translations: Translations;
    idempotencyKey: string;
    uploadToken: string;
    uploadUrl: string;
    /** Llave PÚBLICA de Turnstile; vacía si el sitio no la configuró. */
    turnstileSiteKey: string;
    minParticipants: number;
    maxParticipants: number;
    tracks: SubmissionTrack[];
    privacyNotice: { id: string; version: number; url: string };
}

/** Valor de una respuesta dinámica tal como la acepta el servidor. */
export type AnswerValue = string | boolean | string[];

export type ParticipantPayload = {
    first_name: string;
    last_name: string;
    email: string;
    country: string;
    phone?: string;
    job_title?: string;
    company?: string;
    bio?: string;
    is_primary: boolean;
};

/** Cuerpo del envío (`StoreSubmissionRequest`). Es un `type` para que Inertia lo acepte como datos de la petición. */
export type SubmissionPayload = {
    idempotency_key: string;
    upload_token: string;
    form_version: number;
    locale: string;
    title: string;
    track_id: string;
    answers: Record<string, AnswerValue>;
    participants: ParticipantPayload[];
    consent: boolean;
    privacy_notice_version_id: string;
    third_parties_authorized: boolean;
    turnstile_token: string;
};

/** Códigos con que el servidor rechaza un envío sin salir de la página, más los dos del cliente. */
export type SubmitFailureCode =
    | 'CALL_NOT_OPEN'
    | 'CALL_NOT_READY'
    | 'FORM_OUTDATED'
    | 'IDEMPOTENCY_PAYLOAD_MISMATCH'
    | 'TOO_MANY_REQUESTS'
    | 'TURNSTILE_UNAVAILABLE'
    | 'NETWORK'
    | 'GENERIC';

export interface SubmitFailure {
    code: SubmitFailureCode;
    /** Mensaje ya traducido por el servidor, si lo hay. */
    message: string | null;
    retryAfterSeconds: number | null;
}
