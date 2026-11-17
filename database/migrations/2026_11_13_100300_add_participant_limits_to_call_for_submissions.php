<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Mínimo y máximo de participantes por propuesta de una convocatoria (plan 006, RC-9). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_participants')->default(1);
            $table->unsignedSmallInteger('max_participants')->default(5);
        });

        DB::statement('ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_min_participants_check CHECK (min_participants >= 1)');
        DB::statement('ALTER TABLE call_for_submissions ADD CONSTRAINT call_for_submissions_max_participants_check CHECK (max_participants >= min_participants)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE call_for_submissions DROP CONSTRAINT call_for_submissions_max_participants_check');
        DB::statement('ALTER TABLE call_for_submissions DROP CONSTRAINT call_for_submissions_min_participants_check');

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropColumn(['min_participants', 'max_participants']);
        });
    }
};
