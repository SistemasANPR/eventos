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
 * @property string|null $sponsor_category_id
 * @property string|null $website_url
 * @property string|null $logo_path
 * @property int $position
 * @property array<string, string|array{path: string}>|null $extra_fields respuestas a las preguntas del evento: id de la pregunta => texto o {path} de la foto
 */
class Sponsor extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SponsorFactory> */
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
     * Su nivel: la categoría de patrocinador del evento (plan 030, DA-6); puede no tener.
     *
     * @return BelongsTo<SponsorCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(SponsorCategory::class, 'sponsor_category_id');
    }
}
