<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\ImportAnonymizationLog;
use App\Actions\Privacy\ReapplyAnonymizations;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Tras restaurar un respaldo, vuelve a anonimizar a las personas ya anonimizadas (plan 014, H-014-3, CA-014-19).
 * Procedimiento completo en `docs/operacion.md` §5: antes de restaurar se guarda el registro con
 * `app:export-anonymization-log` y después se ejecuta este comando con `--from-file` (que lo carga y reaplica).
 *
 * Termina con error si alguna persona registrada sigue con sus datos (último administrador, cuenta de
 * plataforma o un archivo que el disco no pudo borrar) o si no se pudo leer el archivo, para que se vea.
 * Es idempotente. El informe son solo totales: nunca imprime correos ni nombres.
 */
final class ReapplyAnonymizationsCommand extends Command
{
    protected $signature = 'app:reapply-anonymizations
        {--organization= : Solo esta organizacion (su identificador o el de su URL)}
        {--from-file= : Registro guardado con app:export-anonymization-log, que se carga antes de reaplicar}
        {--dry-run : Cuenta las personas que se reaplicarian sin cambiar nada}';

    protected $description = 'Vuelve a anonimizar, en los datos restaurados de un respaldo, a las personas del registro de anonimizaciones';

    public function handle(ReapplyAnonymizations $reapply, ImportAnonymizationLog $import): int
    {
        $simulate = (bool) $this->option('dry-run');
        $file = $this->option('from-file');

        if ($simulate && is_string($file)) {
            $this->error('--dry-run no se puede combinar con --from-file: cargar el registro cambia la base.');

            return self::FAILURE;
        }

        if (is_string($file) && ! $this->importLog($import, $file)) {
            return self::FAILURE;
        }

        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $incomplete = false;
        $totals = ['registered' => 0, 'found' => 0, 'reapplied' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($organizations as $organization) {
            $report = $reapply->handle($organization, $simulate);

            $this->line("Organizacion {$organization->id}: registradas {$report->registered}, con datos {$report->found}, ".($simulate ? 'se reaplicarian' : 'reaplicadas')." {$report->reapplied}, omitidas {$report->skippedTotal()}, con error {$report->failed}.");

            foreach ($report->skipped as $reason => $count) {
                $this->warn("  Omitidas por {$reason}: {$count}. Resolverlo y volver a ejecutar.");
            }

            $incomplete = $incomplete || $report->isIncomplete();
            $totals['registered'] += $report->registered;
            $totals['found'] += $report->found;
            $totals['reapplied'] += $report->reapplied;
            $totals['skipped'] += $report->skippedTotal();
            $totals['failed'] += $report->failed;
        }

        $this->info(($simulate ? 'Simulacion: se reaplicarian ' : 'Personas reaplicadas: ')."{$totals['reapplied']} de {$totals['found']} con datos ({$totals['registered']} registradas).");

        return $incomplete ? self::FAILURE : self::SUCCESS;
    }

    private function importLog(ImportAnonymizationLog $import, string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No se puede leer el archivo del registro.');

            return false;
        }

        try {
            $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('El archivo del registro no es un JSON valido.');

            return false;
        }

        try {
            $result = $import->handle(is_array($payload) ? $payload : []);
        } catch (ValidationException) {
            $this->error('El archivo del registro no tiene el formato esperado; no se cargo nada.');

            return false;
        }

        $this->line("Registro cargado: {$result['added']} huella(s) nueva(s), {$result['existing']} ya presente(s), {$result['skipped']} de organizaciones que no existen.");

        return true;
    }

    /** @return list<Organization>|null `null` si la organización pedida no existe */
    private function organizations(): ?array
    {
        $wanted = $this->option('organization');

        if (! is_string($wanted) || $wanted === '') {
            return Organization::query()->orderBy('id')->get()->all();
        }

        $organization = Str::isUuid($wanted)
            ? Organization::query()->find($wanted)
            : Organization::query()->where('slug', $wanted)->first();

        if ($organization === null) {
            $this->error('No existe esa organizacion.');

            return null;
        }

        return [$organization];
    }
}
