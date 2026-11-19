<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LandingImageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Imagen de la landing de un evento (plan 022, DA-7). El archivo está en el disco privado `media`
 * bajo `organizations/{id}/events/{id}/media/landing/` con un nombre generado por el servidor; los
 * bloques guardan solo el identificador de esta fila. Sin `$fillable`: la crea `UploadLandingImage`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $event_landing_id
 * @property string $path
 * @property string $mime
 * @property int $size_bytes
 * @property int $width
 * @property int $height
 * @property Carbon|null $created_at
 */
class LandingImage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<LandingImageFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<EventLanding, $this> */
    public function landing(): BelongsTo
    {
        return $this->belongsTo(EventLanding::class, 'event_landing_id');
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
