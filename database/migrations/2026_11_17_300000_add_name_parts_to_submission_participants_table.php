<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nombre y apellido por separado en los participantes (plan 021, DA-9, F4).
 *
 * Las filas existentes quedan con `first_name` y `last_name` nulos y su `full_name` intacto; el CHECK
 * exige las dos partes juntas (no vacías y coherentes con `full_name`) o ninguna. `full_name` pasa de
 * 120 a 201 caracteres: «nombre apellido» con dos partes de 100 ya no cabe en 120.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE submission_participants ADD COLUMN first_name varchar(100) NULL, ADD COLUMN last_name varchar(100) NULL');
        DB::statement('ALTER TABLE submission_participants ALTER COLUMN full_name TYPE varchar(201)');
        DB::statement(<<<'SQL'
            ALTER TABLE submission_participants ADD CONSTRAINT submission_participants_name_parts_check CHECK (
                (first_name IS NULL AND last_name IS NULL)
                OR (
                    btrim(first_name) <> '' AND btrim(last_name) <> ''
                    AND first_name = btrim(first_name) AND last_name = btrim(last_name)
                    AND full_name = first_name || ' ' || last_name
                )
            )
            SQL);
    }

    public function down(): void
    {
        // Se pierde la separación de las filas nuevas; `full_name` ya conserva el texto completo.
        DB::statement('ALTER TABLE submission_participants DROP CONSTRAINT submission_participants_name_parts_check');
        DB::statement('ALTER TABLE submission_participants DROP COLUMN first_name, DROP COLUMN last_name');
        DB::statement('ALTER TABLE submission_participants ALTER COLUMN full_name TYPE varchar(120) USING left(full_name, 120)');
    }
};
