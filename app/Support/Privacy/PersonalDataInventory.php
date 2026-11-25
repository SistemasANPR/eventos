<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use App\Models\Activity;
use App\Models\ActivityCategory;
use App\Models\AnonymizationLog;
use App\Models\CallForSubmissions;
use App\Models\Contact;
use App\Models\DecisionBatch;
use App\Models\Event;
use App\Models\EventActivity;
use App\Models\EventAssignment;
use App\Models\EventLanding;
use App\Models\FormDefinition;
use App\Models\KnownDevice;
use App\Models\LandingImage;
use App\Models\Lodging;
use App\Models\MailDispatch;
use App\Models\MailTemplate;
use App\Models\NotificationSetting;
use App\Models\Organization;
use App\Models\PrivacyNotice;
use App\Models\RetentionPolicy;
use App\Models\Review;
use App\Models\ReviewAnswer;
use App\Models\ReviewAssignment;
use App\Models\ReviewRevision;
use App\Models\ReviewScopeRule;
use App\Models\Rubric;
use App\Models\RubricCriterion;
use App\Models\Speaker;
use App\Models\SpeakerCategory;
use App\Models\Sponsor;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionFile;
use App\Models\SubmissionParticipant;
use App\Models\SubmissionStatusChange;
use App\Models\SubmissionType;
use App\Models\Track;
use App\Models\User;

/**
 * Inventario ejecutable de los datos personales de `app/Models` (plan 014, CA-014-26): para cada modelo,
 * o bien está CUBIERTO por la anonimización (`PersonDataEraser`) con cada columna clasificada, o bien está
 * EXENTO con la razón. `PersonalDataInventoryTest` falla si un modelo nuevo no aparece aquí o si una tabla
 * cubierta gana una columna sin clasificar, y comprueba que las columnas `replaced` sí cambian al anonimizar.
 *
 * Clasificación de las columnas de un modelo cubierto:
 * - `replaced`: el dato personal se sustituye por un valor no identificable (siempre cambia).
 * - `redacted`: texto libre del que se quitan el nombre y el correo de la persona si aparecen.
 * - `retained`: sin dato personal propio (identificadores, estados, fechas, configuración) o evidencia que se
 *   conserva a propósito; el comentario de cada grupo explica por qué.
 * - `deleted`: las filas se borran.
 *
 * Una columna personal nueva se declara aquí en el mismo cambio que la introduce, junto a `docs/privacidad.md` §2.
 */
final class PersonalDataInventory
{
    /**
     * @var array<class-string, array{replaced?: list<string>, redacted?: list<string>, retained?: list<string>, deleted?: bool}>
     */
    public const COVERED = [
        Contact::class => [
            'replaced' => ['full_name', 'email', 'country', 'anonymized_at'],
            'retained' => ['id', 'organization_id', 'created_at', 'updated_at'],
        ],
        SubmissionParticipant::class => [
            'replaced' => ['full_name', 'first_name', 'last_name', 'second_last_name', 'email', 'alt_email', 'phone', 'country', 'state', 'city', 'photo_file_id', 'job_title', 'company', 'bio', 'anonymized_at'],
            'retained' => ['id', 'organization_id', 'submission_id', 'contact_id', 'position', 'is_primary', 'created_at', 'updated_at'],
        ],
        Speaker::class => [
            'replaced' => ['first_name', 'last_name', 'email', 'country', 'company', 'job_title', 'tagline', 'bio', 'photo_path', 'social_links', 'is_published', 'anonymized_at'],
            'retained' => ['id', 'organization_id', 'event_id', 'contact_id', 'hidden_by_reversal_at', 'created_by_submission_id', 'deleted_at', 'created_at', 'updated_at'],
        ],
        Submission::class => [
            // Los comentarios de la decisión son de la persona (se sustituyen); el título y las respuestas son contenido
            // de la propuesta: solo se les quita el nombre y el correo. Lo demás es evidencia sin dato personal.
            'replaced' => ['author_comment'],
            'redacted' => ['title', 'answers'],
            'retained' => [
                'id', 'organization_id', 'event_id', 'origin', 'call_for_submissions_id', 'submission_type_id', 'registered_by_user_id',
                'authorization_declared_at', 'form_definition_id', 'form_version', 'form_snapshot', 'track_id', 'status', 'locale',
                'idempotency_key', 'payload_hash', 'privacy_notice_version_id', 'consented_at', 'third_parties_authorized_at',
                'submitted_at', 'withdrawn_at', 'version', 'created_at', 'updated_at', 'deleted_at', 'results_published_at',
            ],
        ],
        SubmissionStatusChange::class => [
            // Quién decidió y cuándo es evidencia; el texto libre de la decisión se sustituye.
            'replaced' => ['comment', 'author_comment'],
            'retained' => ['id', 'organization_id', 'event_id', 'submission_id', 'from_status', 'to_status', 'actor_user_id', 'decision_batch_id', 'created_at'],
        ],
        SubmissionFile::class => [
            // El contenido ya se borró del disco; queda tipo, tamaño y fechas.
            'replaced' => ['storage_path', 'original_name', 'sha256', 'anonymized_at'],
            'retained' => [
                'id', 'organization_id', 'event_id', 'call_id', 'submission_id', 'form_field_key', 'upload_token_hash', 'mime_type',
                'size_bytes', 'status', 'uploaded_at', 'created_at', 'updated_at', 'deleted_at',
            ],
        ],
        SubmissionAccessToken::class => [
            // El enlace se invalida: otro hash, caducado y consumido.
            'replaced' => ['token_hash', 'expires_at', 'consumed_at'],
            'retained' => ['id', 'organization_id', 'submission_id', 'created_at', 'updated_at'],
        ],
        MailDispatch::class => [
            // La huella del destinatario deja de corresponder a un correo; clave, estado y fechas se conservan.
            'replaced' => ['recipient_hash'],
            'retained' => ['id', 'organization_id', 'notification_key', 'idempotency_key', 'status', 'attempts', 'sent_at', 'created_at', 'updated_at'],
        ],
        Review::class => [
            // Sin dato personal propio: la identidad del evaluador es el `reviewer_id` de la asignación, que apunta a su cuenta.
            'retained' => ['id', 'organization_id', 'review_assignment_id', 'rubric_id', 'submitted_at', 'revision', 'created_at', 'updated_at', 'deleted_at'],
        ],
        ReviewAnswer::class => [
            // El comentario de un evaluador anonimizado se sustituye; si es un comentario sobre la persona, se le quita su nombre.
            'replaced' => ['value_text'],
            'retained' => ['id', 'organization_id', 'review_id', 'rubric_criterion_id', 'value_int', 'created_at', 'updated_at'],
        ],
        ReviewRevision::class => [
            'replaced' => ['answers'],
            'retained' => ['id', 'organization_id', 'review_id', 'rubric_id', 'revision', 'submitted_at', 'created_at'],
        ],
        ReviewAssignment::class => [
            // Solo identificadores y estados: con la cuenta anonimizada dejan de apuntar a nadie.
            'retained' => ['id', 'organization_id', 'event_id', 'submission_id', 'reviewer_id', 'status', 'assigned_by', 'assigned_at', 'source', 'created_at', 'updated_at'],
        ],
        User::class => [
            'replaced' => [
                'name', 'email', 'email_verified_at', 'password', 'remember_token', 'app_authentication_secret',
                'app_authentication_recovery_codes', 'two_factor_confirmed_at', 'anonymized_at',
            ],
            'retained' => ['id', 'is_platform_admin', 'created_at', 'updated_at'],
        ],
        KnownDevice::class => [
            'deleted' => true,
            'retained' => ['id', 'user_id', 'fingerprint_hash', 'last_seen_at', 'created_at', 'updated_at'],
        ],
        Activity::class => [
            // La entrada se conserva (quién, qué, cuándo, con identificadores sin dato personal); del texto se quita el nombre y el correo.
            'redacted' => ['description', 'properties', 'attribute_changes'],
            'retained' => ['id', 'log_name', 'subject_type', 'subject_id', 'event', 'causer_type', 'causer_id', 'created_at', 'updated_at', 'organization_id'],
        ],
    ];

    /**
     * Modelos sin dato personal de personas, con la razón. Si uno gana una columna personal, pasa a COVERED.
     *
     * @var array<class-string, string>
     */
    public const EXEMPT = [
        Organization::class => 'Es el responsable del tratamiento: nombre e identificador de la organización, no de una persona.',
        ActivityCategory::class => 'Catálogo de categorías del programa definido por la organización.',
        CallForSubmissions::class => 'Configuración de la convocatoria de la organización.',
        Event::class => 'Configuración del evento de la organización (nombre, fechas, idiomas, logotipo institucional).',
        EventActivity::class => 'Texto e imágenes del programa que redacta la organización; los ponentes se vinculan por identificador (la ficha es `Speaker`).',
        EventAssignment::class => 'Vincula a un miembro del equipo con un evento por identificador; sin dato personal propio.',
        EventLanding::class => 'Contenido de la página pública redactado por la organización.',
        FormDefinition::class => 'Definición del formulario (campos y reglas), sin respuestas de personas.',
        LandingImage::class => 'Imágenes de la página pública cargadas por la organización.',
        Lodging::class => 'Hoteles recomendados del evento (nombre, dirección, enlace de reserva): datos de un negocio que registra la organización, sin personas.',
        Sponsor::class => 'Patrocinadores del evento (nombre de la empresa, logotipo, nivel y sitio web): datos de una organización, sin personas.',
        MailTemplate::class => 'Plantillas de correo de la organización, sin destinatarios.',
        NotificationSetting::class => 'Interruptores de notificaciones de la organización.',
        PrivacyNotice::class => 'Texto del aviso de privacidad; `published_by` es el identificador de un miembro del equipo.',
        ReviewScopeRule::class => 'Regla de asignación de evaluadores por eje o tipo: identificadores, sin dato personal.',
        Rubric::class => 'Rúbrica de evaluación definida por la organización.',
        RubricCriterion::class => 'Criterio de una rúbrica definido por la organización.',
        SpeakerCategory::class => 'Catálogo de categorías de ponentes definido por la organización.',
        SubmissionType::class => 'Tipo de propuesta definido por la organización.',
        Track::class => 'Eje temático definido por la organización.',
        DecisionBatch::class => 'Lote de decisión masiva (plan 012 F2): quién decidió (identificador de un miembro del equipo), filtros estructurados validados (identificadores, estado y fechas, sin texto libre) y el conteo; sin dato personal de personas.',
        AnonymizationLog::class => 'Solo el HMAC no reversible del correo (sal propia de cada organización) y la fecha: es la huella que impide perder una anonimización al restaurar.',
        RetentionPolicy::class => 'Plazos de retención por categoría: configuración de la organización.',
    ];

    /** @return list<string> */
    public static function allColumns(string $model): array
    {
        $entry = self::COVERED[$model] ?? [];

        return [...($entry['replaced'] ?? []), ...($entry['redacted'] ?? []), ...($entry['retained'] ?? [])];
    }
}
