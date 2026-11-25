<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sala de un evento (plan 031, DA-2): el espacio donde ocurren las actividades. Catálogo propio de
 * cada evento, con nombre y orden; no es una sede ni se comparte entre eventos. Sin `$fillable`: se
 * crea y edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property int $position
 */
class Room extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return HasMany<EventActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(EventActivity::class);
    }
}
