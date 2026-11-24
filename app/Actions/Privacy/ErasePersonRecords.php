<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Enums\OrganizationRole;
use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\PersonCannotBeAnonymized;
use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Models\AnonymizationLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Auth\OrganizationAdmins;
use App\Support\Privacy\AnonymizationResult;
use App\Support\Privacy\PersonDataEraser;
use App\Support\Privacy\PersonIdentity;
use App\Support\Privacy\PersonRecords;
use App\Support\Privacy\Redactor;
use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Anonimiza a la persona localizada en `$records`, dentro de la organización en contexto (plan 014, DA-4 y DA-5).
 * Es el motor que comparten `AnonymizePerson` (a petición, con autorización y auditoría) y
 * `ReapplyAnonymizations` (tras restaurar un respaldo). NO autoriza ni valida: lo hace quien lo llama.
 *
 * Orden: 1) comprobaciones de la cuenta, antes de tocar nada; 2) borrar los archivos del disco (si falla,
 * la base sigue intacta y se repite); 3) una transacción con todo lo demás, la huella en
 * `anonymization_log` y el `$audit` del llamador. Idempotente: lo ya anonimizado no se vuelve a localizar.
 *
 * Cuenta de acceso (`User`), que es global y puede pertenecer a otras organizaciones:
 * - de plataforma, o la de quien lo pide: se rechaza todo (`PersonCannotBeAnonymized`);
 * - el último administrador activo de la organización: se rechaza todo (`LastOrganizationAdmin`);
 * - con membresía en otra organización: se conserva la cuenta, se desactiva su membresía aquí y se anonimiza
 *   lo suyo en esta organización (comentarios de evaluación, auditoría); nota `account_kept_other_organizations`;
 * - en cualquier otro caso: se anonimiza la cuenta entera.
 *
 * @throws PersonCannotBeAnonymized
 * @throws LastOrganizationAdmin
 * @throws PersonFileDeletionFailed
 */
final class ErasePersonRecords
{
    public function __construct(private readonly PersonDataEraser $eraser) {}

    /** @param  (Closure(AnonymizationResult): void)|null  $audit  se ejecuta dentro de la transacción, al final */
    public function handle(PersonRecords $records, ?User $actor = null, ?Closure $audit = null): AnonymizationResult
    {
        $organization = CurrentOrganization::get();
        $hashes = array_map(fn (string $email): string => PersonIdentity::organizationHash($email, $organization->id), $records->emails);
        $user = $records->userId === null ? null : User::query()->findOrFail($records->userId);

        if ($user !== null) {
            $this->assertAccountCanBeErased($organization, $user, $actor);
        }

        $needles = $this->needles($records, $user);
        $counts = ['files_deleted' => $this->eraser->deleteBlobs($records->primarySubmissionIds, $records->speakerIds)];

        return DB::transaction(function () use ($organization, $records, $user, $needles, $hashes, $counts, $audit): AnonymizationResult {
            // Serializa las anonimizaciones de la organización: la huella es única por persona.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->sole();

            if ($user !== null) {
                $this->assertNotLastAdmin($organization, $user);
            }

            $counts += [
                'submission_files' => $this->eraser->eraseFiles($records->primarySubmissionIds),
                'access_tokens' => $this->eraser->invalidateAccessTokens($records->primarySubmissionIds),
                'decision_comments' => $this->eraser->eraseDecisionComments($records->primarySubmissionIds),
                'submission_texts' => $this->eraser->redactSubmissionTexts($records->submissionIds, $needles),
                'review_comments' => $this->eraser->redactReviewComments($records->submissionIds, $needles),
                'mail_dispatches' => $this->eraser->eraseMailDispatches($records->emails),
                'participants' => $this->eraser->eraseParticipants($records->participantIds),
                'speakers' => $this->eraser->eraseSpeakers($records->speakerIds),
                'contacts' => $this->eraser->eraseContacts($records->contactIds),
            ];

            $notes = [];
            $accountErased = false;

            if ($user !== null) {
                $counts['review_comments'] += $this->eraser->eraseReviewerComments($user->id);
                $this->eraser->deactivateMembership($user, $organization);
                $accountErased = ! $user->organizations()->whereKeyNot($organization->id)->exists();

                if ($accountErased) {
                    $this->eraser->eraseAccount($user);
                } else {
                    $notes[] = AnonymizationResult::NOTE_ACCOUNT_KEPT_OTHER_ORGANIZATIONS;
                }
            }

            $counts['accounts'] = $accountErased ? 1 : 0;
            $counts['audit_entries'] = $this->eraser->redactAuditLog($needles, $accountErased ? $user?->id : null, $records->emails);

            foreach ($hashes as $hash) {
                if (! AnonymizationLog::query()->where('email_hash', $hash)->exists()) {
                    AnonymizationLog::query()->forceCreate(['email_hash' => $hash, 'anonymized_at' => now()]);
                }
            }

            // Sin datos de facturación en R1 no hay nada que conservar por plazo fiscal (CA-014-22): cuando exista
            // el módulo de pagos (ADR-0007), sus categorías conservadas se listan aquí.
            $result = new AnonymizationResult($counts, retained: [], notes: $notes);

            if ($audit !== null) {
                $audit($result);
            }

            return $result;
        });
    }

    /** Antes de tocar nada: lo que hace imposible anonimizar la cuenta. */
    private function assertAccountCanBeErased(Organization $organization, User $user, ?User $actor): void
    {
        if ($user->is_platform_admin) {
            throw PersonCannotBeAnonymized::platformAccount();
        }

        if ($actor !== null && $actor->id === $user->id) {
            throw PersonCannotBeAnonymized::ownAccount();
        }

        $this->assertNotLastAdmin($organization, $user);
    }

    private function assertNotLastAdmin(Organization $organization, User $user): void
    {
        $isActiveAdmin = User::query()
            ->activeMembersOf($organization)
            ->role(OrganizationRole::OrgAdmin->value)
            ->whereKey($user->id)
            ->exists();

        if ($isActiveAdmin) {
            OrganizationAdmins::assertAnotherRemains($organization, $user);
        }
    }

    /**
     * Las cadenas que identifican a la persona en el texto libre: sus correos y sus nombres completos.
     *
     * @return list<string>
     */
    private function needles(PersonRecords $records, ?User $user): array
    {
        $names = collect([$user?->name])
            ->merge(Contact::query()->whereKey($records->contactIds)->pluck('full_name'))
            ->merge(SubmissionParticipant::query()->whereKey($records->participantIds)->pluck('full_name'))
            ->merge(Speaker::withTrashed()->whereKey($records->speakerIds)->get(['first_name', 'last_name'])
                ->map(fn (Speaker $speaker): string => trim("{$speaker->first_name} {$speaker->last_name}")))
            ->reject(fn (?string $name): bool => $name === PersonDataEraser::NAME);

        return Redactor::needles([...$records->emails, ...$names]);
    }
}
