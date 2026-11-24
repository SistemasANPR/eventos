<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Job de prueba del contrato de contexto bajo RLS (plan 014, CA-014-12): trae su
 * organización en la carga, abre su contexto con `run()` y consulta y modifica con
 * SQL directo (que no pasa por el scope de Eloquent), de modo que lo que ve y toca
 * lo decide la base de datos.
 */
final class ProbeRlsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @var array{visible: int, touched: int}|null */
    public static ?array $seen = null;

    /** @var list<array{visible: int, touched: int}> */
    public static array $history = [];

    public function __construct(public readonly string $organizationId) {}

    public static function reset(): void
    {
        self::$seen = null;
        self::$history = [];
    }

    public function handle(): void
    {
        $seen = CurrentOrganization::run(Organization::query()->findOrFail($this->organizationId), fn (): array => [
            'visible' => DB::table('contacts')->count(),
            'touched' => DB::table('contacts')->update(['full_name' => 'tocado']),
        ]);

        self::$seen = $seen;
        self::$history[] = $seen;
    }
}
