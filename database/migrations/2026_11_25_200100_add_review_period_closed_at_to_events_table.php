<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cierre del periodo de evaluación de un evento (plan 011, F4 lo lee; F6 lo escribe con `CloseReviewPeriod`).
 * Nulo es periodo abierto; con valor, el instante UTC en que se cerró. Es la representación mínima que F4
 * necesita para negar el envío de evaluaciones (CA-011-26, CA-011-52); quién lo cerró y la auditoría son de F6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('review_period_closed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('review_period_closed_at');
        });
    }
};
