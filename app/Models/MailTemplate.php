<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\MailTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla de correo personalizada de una organización (o de un evento, en R1) para una
 * notificación del catálogo y un idioma (plan 008, H-008-4). Sobrescribe a la predeterminada
 * del sistema, que vive en `lang/`. Sin `$fillable`: se escribe solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $event_id
 * @property string $notification_key
 * @property string $locale
 * @property string $subject
 * @property string $body
 * @property string|null $from_name
 * @property string|null $reply_to
 */
class MailTemplate extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MailTemplateFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
