<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Si una organización tiene activa una notificación no crítica del catálogo (plan 008,
 * H-008-5). Sin fila, la notificación está activa. Las críticas nunca se desactivan.
 * Sin `$fillable`: se escribe solo desde `SetNotificationEnabled`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $notification_key
 * @property bool $is_enabled
 */
class NotificationSetting extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<NotificationSettingFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
