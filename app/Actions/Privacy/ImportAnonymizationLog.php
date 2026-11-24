<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\AnonymizationLog;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Vuelve a cargar en `anonymization_log` las huellas que `ExportAnonymizationLog` guardó antes de restaurar un
 * respaldo (plan 014, DA-5). Añade solo las que faltan (idempotente) y todo o nada: un archivo mal formado
 * rechaza el conjunto sin cambiar nada. Las organizaciones que ya no existen en lo restaurado se omiten.
 *
 * @throws ValidationException el archivo no tiene el formato esperado
 */
final class ImportAnonymizationLog
{
    /**
     * @param  array<string, mixed>  $payload  lo que devolvió `ExportAnonymizationLog`
     * @return array{added: int, existing: int, skipped: int} entradas añadidas, ya presentes y de organizaciones inexistentes
     */
    public function handle(array $payload): array
    {
        /** @var array{version: int, entries: list<array{organization_id: string, email_hash: string, anonymized_at: string}>} $valid */
        $valid = Validator::make($payload, [
            'version' => ['required', 'integer', 'in:1'],
            'entries' => ['present', 'array'],
            'entries.*.organization_id' => ['required', 'uuid'],
            'entries.*.email_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'entries.*.anonymized_at' => ['required', 'date'],
        ])->validate();

        $report = ['added' => 0, 'existing' => 0, 'skipped' => 0];

        DB::transaction(function () use ($valid, &$report): void {
            foreach ($valid['entries'] as $entry) {
                $organization = Organization::query()->find($entry['organization_id']);

                if ($organization === null) {
                    $report['skipped']++;

                    continue;
                }

                CurrentOrganization::run($organization, function () use ($entry, &$report): void {
                    if (AnonymizationLog::query()->where('email_hash', $entry['email_hash'])->exists()) {
                        $report['existing']++;

                        return;
                    }

                    AnonymizationLog::query()->forceCreate([
                        'email_hash' => $entry['email_hash'],
                        'anonymized_at' => CarbonImmutable::parse($entry['anonymized_at']),
                    ]);
                    $report['added']++;
                });
            }
        });

        return $report;
    }
}
