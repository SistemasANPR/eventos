<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Membresía de un usuario en una organización. Solo membresía: el rol vive en
 * las tablas de `spatie/laravel-permission` con la organización como team
 * (plan 001, DA-1). El `id` lo genera la base para poder usar `attach()` sin
 * una clase de pivote.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        DB::statement("ALTER TABLE organization_user ADD CONSTRAINT organization_user_status_check CHECK (status IN ('active', 'deactivated'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
