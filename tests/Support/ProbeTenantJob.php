<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Contact;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Job de prueba: consulta un modelo con tenant sin establecer contexto. */
final class ProbeTenantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        Contact::query()->get();
    }
}
