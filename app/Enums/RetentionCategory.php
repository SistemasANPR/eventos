<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Categorías de datos con plazo de retención por organización (plan 014, H-014-6, tabla
 * `retention_policies`). El `CHECK` de la base admite exactamente estos valores.
 *
 * `Backups` es solo declarativa: fija cuántos días puede conservarse un respaldo (DA-5) para el
 * runbook de restauración y la revisión del proveedor; ningún comando borra respaldos.
 */
enum RetentionCategory: string
{
    /** Propuestas rechazadas o retiradas: se anonimiza a sus autores y se borran sus archivos. */
    case UnacceptedSubmissions = 'unaccepted_submissions';

    /** Comentarios de las evaluaciones: se sustituye el texto; se conservan las calificaciones. */
    case Reviews = 'reviews';

    /** Envíos de correo: se rompe el vínculo con el destinatario (huella del correo). */
    case MailDispatches = 'mail_dispatches';

    /** Registros de `activity_log` de la organización: se eliminan los vencidos. */
    case AuditLog = 'audit_log';

    /** Días máximos de conservación de un respaldo (solo declarativa). */
    case Backups = 'backups';

    /** ¿El trabajo programado actúa sobre datos de esta categoría? */
    public function isActionable(): bool
    {
        return $this !== self::Backups;
    }

    public function label(): string
    {
        return __("privacy_admin.retention.categories.{$this->value}");
    }
}
