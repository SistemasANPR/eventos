<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\LodgingImageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una imagen de la galería de un hospedaje (plan 030, DA-5). `path` es una ruta del disco `media`, nunca una
 * URL. Sin `$fillable`: se crea y borra solo desde las Actions de hospedaje.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $lodging_id
 * @property string $path
 * @property int $position
 */
class LodgingImage extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<LodgingImageFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<Lodging, $this> */
    public function lodging(): BelongsTo
    {
        return $this->belongsTo(Lodging::class);
    }
}
