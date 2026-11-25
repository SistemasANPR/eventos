<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SponsorCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría («Nivel») de patrocinador de un evento (plan 030, DA-6): catálogo propio del evento, como el de
 * ponentes. Sin `$fillable`: se crea y edita solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property int $position
 */
class SponsorCategory extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SponsorCategoryFactory> */
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

    /** @return HasMany<Sponsor, $this> */
    public function sponsors(): HasMany
    {
        return $this->hasMany(Sponsor::class);
    }
}
