<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\EventType;
use App\Enums\TimeFormat;
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
 * @property TimeFormat $time_format
 */
#[Fillable([
    'name', 'description', 'slug', 'type', 'starts_at', 'ends_at', 'timezone',
    'venue_name', 'venue_address', 'city', 'locales', 'default_locale', 'currency', 'time_format', 'status',
])]
class Event extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<EventFactory> */
    use HasFactory, HasTranslations, HasUuids, SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** Mismo valor por defecto que la columna: un evento recién creado ya sabe su formato de hora (plan 023). */
    protected $attributes = ['time_format' => '12h'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => EventType::class,
            'status' => EventStatus::class,
            'time_format' => TimeFormat::class,
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

    /**
     * Eventos a los que el usuario está asignado con cualquier rol (plan 011,
     * CA-011-28). Un evento ajeno no aparece, así que `findOrFail` responde 404.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopeAssignedTo(Builder $query, User $user): Builder
    {
        return $query->whereIn('id', EventAssignment::query()->where('user_id', $user->id)->select('event_id'));
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

    /** @return HasMany<Track, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    /** @return HasMany<SpeakerCategory, $this> */
    public function speakerCategories(): HasMany
    {
        return $this->hasMany(SpeakerCategory::class);
    }

    /** @return HasMany<Speaker, $this> */
    public function speakers(): HasMany
    {
        return $this->hasMany(Speaker::class);
    }

    /** @return HasMany<ActivityCategory, $this> */
    public function activityCategories(): HasMany
    {
        return $this->hasMany(ActivityCategory::class);
    }

    /** @return HasMany<EventActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(EventActivity::class);
    }

    /** @return HasMany<PrivacyNotice, $this> */
    public function privacyNotices(): HasMany
    {
        return $this->hasMany(PrivacyNotice::class);
    }

    /** @return HasMany<FormDefinition, $this> */
    public function formDefinitions(): HasMany
    {
        return $this->hasMany(FormDefinition::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Idiomas que el evento ofrece, con el idioma por defecto primero (plan 025, fuente única).
     *
     * @return list<string>
     */
    public function offeredLocales(): array
    {
        return array_values(array_unique([$this->default_locale, ...$this->locales]));
    }

    /**
     * Idiomas con que se muestra una convocatoria (plan 025, DA-4): los suyos que siguen en el evento;
     * si no queda ninguno, el idioma por defecto del evento.
     *
     * @return list<string>
     */
    public function effectiveLocalesForCall(CallForSubmissions $call): array
    {
        $effective = array_values(array_intersect($call->locales, $this->offeredLocales()));

        return $effective === [] ? [$this->default_locale] : $effective;
    }

    public function isPublished(): bool
    {
        return $this->status === EventStatus::Published;
    }
}
