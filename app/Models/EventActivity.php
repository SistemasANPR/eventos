<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EventActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Una actividad del programa de un evento: charla, panel, taller o evento social (plan 021, DA-14).
 * No es `Activity`, que es la bitácora de auditoría. Sin `$fillable`: se crea y edita solo desde las
 * Actions. Fecha y hora son LOCALES del evento, sin zona (DA-12): `starts_on`/`ends_on` son fechas y
 * `starts_at`/`ends_at` horas `H:i:s`; o las cuatro o ninguna («sin programar», DA-4). `description`
 * guarda HTML: el saneado llega con el plan 021 F3b (`RichTextSanitizer`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string|null $submission_id
 * @property string $title
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $starts_at hora local `H:i:s`
 * @property string|null $ends_at hora local `H:i:s`
 * @property string|null $icon_path
 * @property string|null $image_path
 * @property string|null $summary
 * @property string|null $description
 * @property bool $is_published
 * @property Carbon|null $hidden_by_reversal_at
 */
class EventActivity extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<EventActivityFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_published' => 'boolean',
            'hidden_by_reversal_at' => 'datetime',
        ];
    }

    /** Con fecha y hora; si no, está «sin programar» (DA-4). */
    public function isScheduled(): bool
    {
        return $this->starts_on !== null;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsToMany<ActivityCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ActivityCategory::class, 'activity_activity_category', 'activity_id', 'activity_category_id');
    }
}
