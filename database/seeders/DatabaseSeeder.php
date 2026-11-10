<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Puebla la base local con datos ficticios. Se niega a correr en producción
     * (`migrate --seed` no debe escribir nada allí) y no crea usuarios con
     * contraseña conocida: las cuentas reales se crean con los comandos de arranque.
     * No usa `WithoutModelEvents`: la siembra de roles depende de los eventos de modelo.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DatabaseSeeder no se puede ejecutar en producción.');
        }

        $this->call(DemoSeeder::class);
    }
}
