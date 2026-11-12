<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MailDispatchStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MailDispatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un envío de correo del catálogo (plan 008, H-008-6). No guarda el destinatario en
 * claro, solo su `recipient_hash`. Sin `$fillable`: lo crea y actualiza solo
 * `SendNotification` y el job de envío.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $notification_key
 * @property string $idempotency_key
 * @property string $recipient_hash
 * @property MailDispatchStatus $status
 * @property int $attempts
 * @property Carbon|null $sent_at
 */
class MailDispatch extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MailDispatchFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['recipient_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MailDispatchStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
