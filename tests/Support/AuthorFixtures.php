<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Event;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\SubmissionAccessToken;
use App\Models\SubmissionParticipant;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Str;

/**
 * Datos de prueba del acceso del autor (plan 006, F5 y F6): propuesta con su participante
 * principal y tokens de acceso conocidos. Deja la organización de la propuesta en contexto.
 */
final class AuthorFixtures
{
    public const TOKEN = 'token-de-prueba-0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * Propuesta `submitted` de un evento publicado, con un participante principal.
     *
     * @param  array<string, mixed>  $submission  atributos de la propuesta
     * @param  array<string, mixed>  $participant  atributos del participante principal
     */
    public static function submission(?Organization $organization = null, array $submission = [], array $participant = [], ?Event $event = null): Submission
    {
        $organization ??= Organization::factory()->create();
        CurrentOrganization::set($organization);

        $event ??= Event::factory()->published()->create([
            'organization_id' => $organization->id,
            'name' => ['es' => 'Congreso Parques', 'en' => 'Parks Congress'],
            'slug' => 'congreso-'.Str::lower(Str::random(6)),
        ]);

        $created = Submission::factory()->create([
            'organization_id' => $organization->id,
            'event_id' => $event->id,
            'title' => 'Parques vivos',
            ...$submission,
        ]);

        SubmissionParticipant::factory()->create([
            'organization_id' => $organization->id,
            'submission_id' => $created->id,
            'full_name' => 'Ana Pérez',
            'email' => 'ana@example.test',
            ...$participant,
        ]);

        return $created;
    }

    /**
     * Otra propuesta del mismo evento (misma convocatoria, tipo, formulario, eje y aviso, para no chocar con
     * las claves únicas de la fábrica), con su participante principal.
     *
     * @param  array<string, mixed>  $overrides  atributos de la propuesta
     */
    public static function sibling(Submission $like, string $email = 'otra@example.test', array $overrides = [], bool $primary = true): Submission
    {
        $created = Submission::factory()->create([
            'organization_id' => $like->organization_id,
            'event_id' => $like->event_id,
            'call_for_submissions_id' => $like->call_for_submissions_id,
            'submission_type_id' => $like->submission_type_id,
            'form_definition_id' => $like->form_definition_id,
            'track_id' => $like->track_id,
            'privacy_notice_version_id' => $like->privacy_notice_version_id,
            ...$overrides,
        ]);

        SubmissionParticipant::factory()->create([
            'organization_id' => $like->organization_id,
            'submission_id' => $created->id,
            'email' => $email,
            'is_primary' => $primary,
        ]);

        return $created;
    }

    /**
     * Token con valor conocido (solo se guarda su hash, como en producción). Nace vigente.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function token(Submission $submission, string $plain = self::TOKEN, array $attributes = []): string
    {
        SubmissionAccessToken::factory()->create([
            'organization_id' => $submission->organization_id,
            'submission_id' => $submission->id,
            'token_hash' => hash('sha256', $plain),
            ...$attributes,
        ]);

        return $plain;
    }

    /** Ruta del autor bajo la organización y el evento de la propuesta. */
    public static function path(Submission $submission, string $suffix = 'my-submission'): string
    {
        $organization = Organization::query()->findOrFail($submission->organization_id);
        $event = Event::query()->withoutGlobalScopes()->findOrFail($submission->event_id);

        return "/{$organization->slug}/events/{$event->slug}/{$suffix}";
    }
}
