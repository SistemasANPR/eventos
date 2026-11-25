<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El enlace propio del pie de la convocatoria pasa de ser uno solo (`extra_link_label`/`extra_link_url`) a una
 * lista de hasta 10 (`extra_links`: [{label, url}]). Lo que ya estuviera guardado se conserva como primer enlace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->json('extra_links')->nullable();
        });

        DB::table('call_for_submissions')
            ->whereNotNull('extra_link_label')
            ->whereNotNull('extra_link_url')
            ->orderBy('id')
            ->each(function (object $call): void {
                DB::table('call_for_submissions')->where('id', $call->id)->update([
                    'extra_links' => json_encode([['label' => $call->extra_link_label, 'url' => $call->extra_link_url]], JSON_UNESCAPED_UNICODE),
                ]);
            });

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropColumn(['extra_link_label', 'extra_link_url']);
        });
    }

    public function down(): void
    {
        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->string('extra_link_label', 100)->nullable();
            $table->string('extra_link_url', 2048)->nullable();
        });

        DB::table('call_for_submissions')->whereNotNull('extra_links')->orderBy('id')->each(function (object $call): void {
            $first = json_decode((string) $call->extra_links, true)[0] ?? null;

            if (is_array($first)) {
                DB::table('call_for_submissions')->where('id', $call->id)->update(['extra_link_label' => $first['label'], 'extra_link_url' => $first['url']]);
            }
        });

        Schema::table('call_for_submissions', function (Blueprint $table): void {
            $table->dropColumn('extra_links');
        });
    }
};
