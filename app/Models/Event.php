<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Una edición concreta de un evento (Congreso Parques 2027): fechas, sede, zona
 * horaria, idiomas, moneda y estado. Pertenece a una organización (ADR-0003).
 *
 * Fechas en UTC; `timezone` solo indica cómo mostrarlas. Se archiva con borrado
 * lógico, nunca físico.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $slug
 * @property EventType $type
 * @property EventStatus $status
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property string|null $venue_name
 * @property string|null $venue_address
 * @property string|null $city
 * @property list<string> $locales
 * @property string $default_locale
 * @property string $currency
 */
#[Fillable([
    'name', 'description', 'slug', 'type', 'starts_at', 'ends_at', 'timezone',
    'venue_name', 'venue_address', 'city', 'locales', 'default_locale', 'currency', 'status',
])]
class Event extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<EventFactory> */
    use HasFactory, HasTranslations, HasUuids, SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'locales' => 'array',
        ];
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', EventStatus::Published);
    }

    /** @return HasMany<CallForSubmissions, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(CallForSubmissions::class);
    }

    /** @return HasMany<SubmissionType, $this> */
    public function submissionTypes(): HasMany
    {
        return $this->hasMany(SubmissionType::class);
    }

    /** @return HasMany<PrivacyNotice, $this> */
    public function privacyNotices(): HasMany
    {
        return $this->hasMany(PrivacyNotice::class);
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }
}
