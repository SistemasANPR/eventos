<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Archivos de propuesta subidos y nunca enviados (plan 007, H-007-3). Vigilancia: docs/operacion.md.
Schedule::command('files:purge-orphans')->dailyAt('03:00')->withoutOverlapping();

// Archivos de exportación de propuestas con más de 24 horas (plan 009, DA-3, CA-009-19). Vigilancia: docs/operacion.md.
Schedule::command('submission-exports:purge')->hourly()->withoutOverlapping();

// Recordatorio de cierre de la convocatoria a los autores con propuestas enviadas (plan 012, H-012-4). Una vez por autor y convocatoria; vigilancia: docs/operacion.md.
Schedule::command('calls:send-closing-reminders')->dailyAt('09:00')->withoutOverlapping();
