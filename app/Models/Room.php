<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoomKind;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lugar de un evento (plan 031, DA-2; plan 035, DA-1): el espacio donde ocurren las actividades. Catálogo
 * propio de cada evento, con nombre y orden; no es una sede ni se comparte entre eventos. Su `kind` dice si
 * es una sala dentro del recinto (uso exclusivo) o un lugar externo (admite actividades simultáneas). Capacidad,
 * dirección y mapa son opcionales; la capacidad es física y solo se muestra al público si `capacity_is_public`.
 * En la interfaz se llama «Lugar». Sin `$fillable`: se crea y edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property int $position
 * @property RoomKind $kind
 * @property int|null $capacity
 * @property bool $capacity_is_public
 * @property string|null $address
 * @property string|null $map_url
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
        return [
            'position' => 'integer',
            'kind' => RoomKind::class,
            'capacity' => 'integer',
            'capacity_is_public' => 'boolean',
        ];
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
