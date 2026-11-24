<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Exceptions\Domain\LastOrganizationAdmin;
use App\Exceptions\Domain\PersonCannotBeAnonymized;
use App\Exceptions\Domain\PersonFileDeletionFailed;
use App\Models\AnonymizationLog;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Speaker;
use App\Models\SubmissionParticipant;
use App\Models\User;
use App\Support\Privacy\PersonIdentity;
use App\Support\Privacy\PersonRecords;
use App\Support\Privacy\ReapplyReport;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Tras restaurar un respaldo, vuelve a anonimizar a las personas de `anonymization_log` que reaparecieron con
 * sus datos (plan 014, H-014-3, DA-5, CA-014-19). Trabaja en UNA organización, con su contexto
 * (`CurrentOrganization::run`): del registro toma las huellas, recorre los correos de los contactos,
 * participantes, ponentes y miembros aún sin anonimizar y, cuando la huella de uno coincide, ejecuta el mismo
 * motor que la anonimización a petición (`ErasePersonRecords`). Es idempotente: sobre datos ya anonimizados no
 * encuentra a nadie. Se puede simular (`$simulate`) para ver cuántas personas se reaplicarían sin cambiar nada.
 *
 * Los casos que la anonimización a petición rechaza (último administrador, cuenta de plataforma) no se fuerzan:
 * se cuentan como omitidos y el comando termina con error, para que alguien los resuelva. Un fallo del disco al
 * borrar un archivo se cuenta como fallo y no detiene a las demás personas. Deja una entrada de auditoría
 * `anonymizations_reapplied` con los totales, sin datos personales.
 */
final class ReapplyAnonymizations
{
    public function __construct(private readonly ErasePersonRecords $erase) {}

    public function handle(Organization $organization, bool $simulate = false): ReapplyReport
    {
        return CurrentOrganization::run($organization, fn (): ReapplyReport => $this->reapply($organization, $simulate));
    }

    private function reapply(Organization $organization, bool $simulate): ReapplyReport
    {
        $registered = AnonymizationLog::query()->pluck('email_hash')->flip();

        if ($registered->isEmpty()) {
            return new ReapplyReport;
        }

        $matches = $this->registeredEmails($organization, $registered->all());
        $found = count($matches);
        $reapplied = 0;
        $failed = 0;
        /** @var array<string, int> $skipped */
        $skipped = [];

        foreach ($matches as $email) {
            // Una persona con varios correos ya se anonimizó entera al tratar otro de ellos.
            $records = PersonRecords::find($email);

            if ($records->isEmpty()) {
                $found--;

                continue;
            }

            if ($simulate) {
                $reapplied++;

                continue;
            }

            try {
                $this->erase->handle($records);
                $reapplied++;
            } catch (LastOrganizationAdmin) {
                $skipped['last_administrator'] = ($skipped['last_administrator'] ?? 0) + 1;
            } catch (PersonCannotBeAnonymized $exception) {
                $skipped[$exception->reason] = ($skipped[$exception->reason] ?? 0) + 1;
            } catch (PersonFileDeletionFailed $exception) {
                report($exception);
                $failed++;
            }
        }

        $report = new ReapplyReport($registered->count(), $found, $reapplied, $skipped, $failed);

        if (! $simulate && ($reapplied > 0 || $report->isIncomplete())) {
            activity()
                ->event('anonymizations_reapplied')
                ->withProperties(['organization_id' => $organization->id, ...$report->toArray()])
                ->log('anonymizations_reapplied');
        }

        return $report;
    }

    /**
     * Los correos de la organización aún sin anonimizar cuya huella está en el registro.
     *
     * @param  array<string, int>  $registered  huellas del registro (como claves)
     * @return list<string>
     */
    private function registeredEmails(Organization $organization, array $registered): array
    {
        $emails = collect()
            ->merge(Contact::query()->whereNull('anonymized_at')->pluck('email'))
            ->merge(SubmissionParticipant::query()->whereNull('anonymized_at')->pluck('email'))
            ->merge(Speaker::withTrashed()->whereNull('anonymized_at')->whereNotNull('email')->pluck('email'))
            ->merge(User::query()->membersOf($organization)->whereNull('anonymized_at')->pluck('email'));

        return $emails
            ->map(fn (string $email): string => PersonIdentity::normalize($email))
            ->unique()
            ->filter(fn (string $email): bool => isset($registered[PersonIdentity::organizationHash($email, $organization->id)]))
            ->values()
            ->all();
    }
}
