<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('full_name');
            $table->string('email');
            $table->char('country', 2)->nullable();
            $table->timestamps();

            // Permite claves foráneas compuestas desde tablas hijas (mismo organization_id).
            $table->unique(['id', 'organization_id']);
        });

        DB::statement('CREATE UNIQUE INDEX contacts_organization_email_unique ON contacts (organization_id, lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
