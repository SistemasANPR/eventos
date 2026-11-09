<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Segunda barrera de aislamiento (ADR-0003): `organization_id` no cambia nunca
 * después de creado la fila, aunque el UPDATE no pase por los eventos de
 * Eloquent (builder, SQL, `saveQuietly`). Toda tabla nueva con tenant añade su
 * propio trigger con esta función; lo vigila `OrganizationIdImmutableTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_organization_id_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.organization_id IS DISTINCT FROM OLD.organization_id THEN
                    RAISE EXCEPTION 'organization_id no se puede cambiar (tabla %)', TG_TABLE_NAME
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER contacts_organization_id_immutable
            BEFORE UPDATE OF organization_id ON contacts
            FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS contacts_organization_id_immutable ON contacts');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_organization_id_change()');
    }
};
