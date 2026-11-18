import type { PublicOrganization } from '@/types/event';

/** Estado de una propuesta: coincide con `App\Enums\SubmissionStatus`. */
export type SubmissionStatus =
    | 'submitted'
    | 'under_review'
    | 'accepted'
    | 'waitlisted'
    | 'rejected'
    | 'confirmed'
    | 'withdrawn';

/** Props de `Author/RequestLink`: coincide con `App\Support\Submissions\AccessLinkPageData`. */
export interface AccessLinkPageProps {
    event: { name: string; locale: string; locales: string[] };
    organization: PublicOrganization;
    /** Llave pública de Turnstile; vacía si el sitio no la tiene configurada. */
    turnstileSiteKey: string;
    /** El correo ya pidió un enlace (el mismo aviso exista o no una propuesta). */
    linkRequested: boolean;
    tooManyRequests: boolean;
    maxLinks: number;
    expiresInMinutes: number;
    eventUrl: string;
}

/** Una respuesta del formulario con la etiqueta con que se envió. */
export interface AuthorAnswer {
    key: string;
    label: string;
    type: string;
    /** Texto, opción elegida, lista de opciones, sí/no o, en archivos, cuántos se enviaron. */
    value: string | boolean | string[] | null;
}

export interface AuthorParticipant {
    fullName: string;
    email: string;
    country: string;
    jobTitle: string | null;
    company: string | null;
    bio: string | null;
    isPrimary: boolean;
}

/** Lo que el autor ve de su propuesta: coincide con `App\Support\Submissions\AuthorSubmissionData`. */
export interface AuthorSubmission {
    reference: string;
    title: string;
    status: SubmissionStatus;
    locale: string;
    version: number;
    /** Instantes en UTC (ISO 8601). */
    submittedAt: string;
    withdrawnAt: string | null;
    track: string;
    answers: AuthorAnswer[];
    participants: AuthorParticipant[];
}
