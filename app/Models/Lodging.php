<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LodgingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Hospedaje recomendado de un evento (plan 030, DA-1). Sin `$fillable`: se crea y edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property string|null $address
 * @property string|null $description
 * @property string|null $booking_url
 * @property string|null $rate
 * @property int $position
 * @property array<string, string|array{path: string}>|null $extra_fields respuestas a las preguntas del evento: id de la pregunta => texto o {path} de la foto
 */
class Lodging extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<LodgingFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['extra_fields' => 'array'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Galería del hospedaje, en su orden (plan 030, DA-5).
     *
     * @return HasMany<LodgingImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(LodgingImage::class)->orderBy('position');
    }
}
