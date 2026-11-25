<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SponsorFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patrocinador de un evento (plan 030, DA-1). Sin `$fillable`: se crea y edita solo desde las Actions.
 * `logo_path` es una ruta del disco `media`, nunca una URL.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $name
 * @property string|null $level
 * @property string|null $website_url
 * @property string|null $logo_path
 * @property int $position
 */
class Sponsor extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SponsorFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
