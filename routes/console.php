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
