<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ActivityContactFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Responsable de una actividad en un lugar externo (plan 035, DA-5 y DA-11): nombre, teléfono y correo de
 * quien atiende al público. Es dato personal: solo lo ve el equipo salvo que `is_public` esté marcado, y se
 * elimina con la actividad o al anonimizar a la persona (`PersonalDataInventory`). Sin `$fillable`: se crea
 * y edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $activity_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property int $position
 * @property bool $is_public
 */
class ActivityContact extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ActivityContactFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'is_public' => 'boolean'];
    }

    /** @return BelongsTo<EventActivity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'activity_id');
    }
}
