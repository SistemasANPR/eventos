<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Privacy\ApplyRetentionPolicy;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Tarea diaria: aplica los plazos de retención de cada organización (plan 014, H-014-6). Una organización sin
 * plazos no hace nada. El fallo de una categoría se reporta y no detiene a las demás organizaciones, pero el
 * comando termina con error para que se vea (vigilancia en `docs/operacion.md` §8). Es idempotente: reintentarla
 * a mano es seguro. El informe son solo totales.
 */
final class ApplyRetentionCommand extends Command
{
    protected $signature = 'retention:apply {--organization= : Solo esta organizacion (su identificador o el de su URL)}';

    protected $description = 'Anonimiza o elimina los datos que superaron el plazo de retencion de cada organizacion';

    public function handle(ApplyRetentionPolicy $apply): int
    {
        $wanted = $this->option('organization');
        $failed = false;

        $query = Organization::query()->orderBy('id');

        if (is_string($wanted) && $wanted !== '') {
            if (Str::isUuid($wanted)) {
                $query->whereKey($wanted);
            } else {
                $query->where('slug', $wanted);
            }
        }

        $organizations = $query->get();

        if (is_string($wanted) && $wanted !== '' && $organizations->isEmpty()) {
            $this->error('No existe esa organizacion.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            $report = $apply->handle($organization);

            if (! $report->applied()) {
                $this->line("Organizacion {$organization->id}: sin plazos de retencion.");

                continue;
            }

            $detail = collect($report->counts)
                ->map(fn (int $count, string $category): string => "{$category}: {$count}")
                ->implode(', ');
            $this->line("Organizacion {$organization->id}: {$detail}.");

            foreach ($report->failed as $category) {
                $this->error("Organizacion {$organization->id}: fallo en {$category}.");
            }

            $failed = $failed || $report->hasFailures();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
