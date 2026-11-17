<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Calls\SeedDefaultSubmissionTypes;
use App\Actions\Organizations\SyncOrganizationRoles;
use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Datos de demostración FICTICIOS para local y `staging` (CA-002-18): una
 * organización y un evento publicado. Se niega a correr en producción, donde
 * nunca debe escribir. Idempotente: volver a ejecutarlo no duplica nada.
 */
class DemoSeeder extends Seeder
{
    public function run(SyncOrganizationRoles $syncRoles, SeedDefaultSubmissionTypes $seedSubmissionTypes): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder no se puede ejecutar en producción.');
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => 'demo'],
            ['name' => 'Organización de demostración'],
        );

        $syncRoles->handle($organization);

        CurrentOrganization::run($organization, function () use ($seedSubmissionTypes): void {
            $event = Event::query()->firstOrCreate(
                ['slug' => 'congreso-demo'],
                [
                    'name' => ['es' => 'Congreso de demostración', 'en' => 'Demo congress'],
                    'description' => [
                        'es' => 'Evento ficticio para probar la plataforma. No es una convocatoria real.',
                        'en' => 'Fictitious event to try the platform. It is not a real call.',
                    ],
                    'type' => 'congress',
                    'starts_at' => '2027-03-10 15:00:00',
                    'ends_at' => '2027-03-12 23:00:00',
                    'timezone' => 'America/Mexico_City',
                    'venue_name' => 'Sede de demostración',
                    'city' => 'Ciudad de México',
                    'locales' => ['es', 'en'],
                    'default_locale' => 'es',
                    'currency' => 'MXN',
                    'status' => EventStatus::Published,
                ],
            );

            $seedSubmissionTypes->handle($event);
        });
    }
}
