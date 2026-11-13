<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Personalización de correos por organización (plan 008, H-008-4 y H-008-5, DA-5).
 *
 * `mail_templates`: una fila por (organización, notificación, idioma) con `event_id` nulo
 * (nivel organización) o por evento (nivel evento, R1). Un `UNIQUE` simple con `event_id`
 * nulo permitiría duplicados en PostgreSQL, por eso son dos índices únicos parciales.
 * La plantilla del sistema no es una tabla: vive en `lang/`.
 *
 * `notification_settings`: activa o desactiva una notificación NO crítica por organización.
 * La propuesta de datos del plan ponía `is_enabled` en `mail_templates`; ahí exigiría una
 * fila por idioma solo para apagar un aviso. Una fila por (organización, notificación) es
 * más simple y no obliga a guardar contenido. Decisión registrada en el plan 008, §9.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id')->nullable();
            $table->string('notification_key', 100);
            $table->string('locale', 5);
            $table->string('subject', 200);
            $table->text('body');
            $table->string('from_name', 100)->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['event_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('events')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE mail_templates ADD CONSTRAINT mail_templates_locale_check CHECK (locale IN ('es', 'en', 'pt'))");
        DB::statement('CREATE UNIQUE INDEX mail_templates_organization_level_unique ON mail_templates (organization_id, notification_key, locale) WHERE event_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX mail_templates_event_level_unique ON mail_templates (organization_id, event_id, notification_key, locale) WHERE event_id IS NOT NULL');

        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->restrictOnDelete();
            $table->string('notification_key', 100);
            $table->boolean('is_enabled')->default(true);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'notification_key']);
        });

        foreach (['mail_templates', 'notification_settings'] as $tableName) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$tableName}_organization_id_immutable
                BEFORE UPDATE OF organization_id ON {$tableName}
                FOR EACH ROW EXECUTE FUNCTION prevent_organization_id_change()
                SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('mail_templates');
    }
};
