<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Submissions\SendClosingReminders;
use App\Models\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Tarea diaria: recordatorio de cierre de la convocatoria a los autores con propuestas enviadas, organización
 * por organización (plan 012, H-012-4). Es idempotente: ejecutarla dos veces el mismo día no duplica ningún
 * correo. El fallo de una organización o de un autor se reporta y no detiene a los demás, pero el comando
 * termina con error para que se vea.
 */
final class SendClosingRemindersCommand extends Command
{
    protected $signature = 'calls:send-closing-reminders';

    protected $description = 'Avisa a los autores con propuestas enviadas que la convocatoria está por cerrar, organización por organización';

    public function handle(SendClosingReminders $reminders): int
    {
        $failed = 0;

        // `Organization` es el tenant: no tiene scope de organización que saltar.
        Organization::query()->orderBy('id')->each(function (Organization $organization) use ($reminders, &$failed): void {
            try {
                $report = $reminders->handle($organization);
                $failed += $report->failed;
                $this->line("Organización {$organization->id}: {$report->sent} recordatorio(s) enviado(s), {$report->failed} fallido(s).");
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error("Organización {$organization->id}: falló el recordatorio (".$exception::class.').');
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
