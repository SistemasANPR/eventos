<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\PurgeOrphanSubmissionFiles;
use App\Models\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tarea diaria: limpia los archivos de propuesta `pending` vencidos de cada
 * organización (plan 007, H-007-3). El fallo de una organización se reporta y no
 * detiene a las demás, pero el comando termina con error para que se vea.
 */
final class PurgeOrphanSubmissionFilesCommand extends Command
{
    protected $signature = 'files:purge-orphans';

    protected $description = 'Borra los archivos de propuesta pendientes con más de 24 horas, organización por organización';

    public function handle(PurgeOrphanSubmissionFiles $purge): int
    {
        $failed = 0;

        // `Organization` es el tenant: no tiene scope de organización que saltar.
        Organization::query()->orderBy('id')->each(function (Organization $organization) use ($purge, &$failed): void {
            try {
                $count = $purge->handle($organization);
                $this->line("Organización {$organization->id}: {$count} archivo(s) huérfano(s) eliminado(s).");
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("Organización {$organization->id}: falló la limpieza (".$exception::class.').');
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
