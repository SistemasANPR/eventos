<?php

declare(strict_types=1);

namespace App\Actions\Privacy;

use App\Models\AnonymizationLog;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Saca el registro de anonimizaciones de TODAS las organizaciones, para conservarlo mientras se restaura un
 * respaldo (plan 014, DA-5): lo restaurado puede ser anterior a esas anonimizaciones y traería un registro
 * incompleto. Solo contiene huellas no reversibles, fechas e identificadores de organización: ningún correo.
 * Aun así es un archivo de plataforma: se guarda fuera de `public/` y se borra al terminar. Lo vuelve a leer
 * `ImportAnonymizationLog`.
 */
final class ExportAnonymizationLog
{
    /** @return array{version: int, entries: list<array{organization_id: string, email_hash: string, anonymized_at: string}>} */
    public function handle(): array
    {
        $entries = [];

        Organization::query()->orderBy('id')->each(function (Organization $organization) use (&$entries): void {
            CurrentOrganization::run($organization, function () use ($organization, &$entries): void {
                // `email_hash` está oculto en el modelo (`$hidden`): se lee por columna, no por serialización.
                foreach (AnonymizationLog::query()->orderBy('anonymized_at')->get(['email_hash', 'anonymized_at']) as $row) {
                    $entries[] = [
                        'organization_id' => $organization->id,
                        'email_hash' => $row->email_hash,
                        'anonymized_at' => $row->anonymized_at->utc()->toIso8601String(),
                    ];
                }
            });
        });

        return ['version' => 1, 'entries' => $entries];
    }
}
