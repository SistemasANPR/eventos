<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\SpeakerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ponente de un evento (plan 021, DA-1): ficha propia del evento, con vínculo opcional a la persona
 * de la organización (`Contact`) y a la propuesta de la que nació. Sin `$fillable`: se crea y edita
 * solo desde las Actions. La biografía se guarda ya saneada (`RichTextSanitizer`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string|null $contact_id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $email
 * @property string|null $country
 * @property string|null $company
 * @property string|null $job_title
 * @property string|null $tagline
 * @property string|null $bio
 * @property string|null $photo_path
 * @property list<array{network: string, url: string}> $social_links
 * @property bool $is_published
 * @property CarbonImmutable|null $hidden_by_reversal_at
 * @property string|null $created_by_submission_id
 * @property-read string $full_name
 */
class Speaker extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SpeakerFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** Tamaño de página de la lista pública de ponentes y su tope (plan 029; AGENTS.md: máximo 100). */
    public const PUBLIC_PER_PAGE = 48;

    public const PUBLIC_MAX_PER_PAGE = 100;

    /** @var list<string> */
    protected $guarded = ['*'];

    /**
     * Lo que el público puede ver (plan 029, DA-6 y DA-7): publicado y no oculto por una reversa. El
     * borrado lógico ya lo excluye el scope de `SoftDeletes`.
     *
     * @param  Builder<Speaker>  $query
     */
    #[Scope]
    protected function visibleToPublic(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_published'), true)
            ->whereNull($this->qualifyColumn('hidden_by_reversal_at'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'is_published' => 'boolean',
            'hidden_by_reversal_at' => 'immutable_datetime',
        ];
    }

    /** Nombre para mostrar: «nombre apellido». */
    /** @return Attribute<string, never> */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function createdBySubmission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'created_by_submission_id');
    }

    /** @return BelongsToMany<SpeakerCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(SpeakerCategory::class, 'speaker_speaker_category');
    }

    /** @return BelongsToMany<EventActivity, $this> */
    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(EventActivity::class, 'activity_speaker', 'speaker_id', 'activity_id')
            ->withPivot('position');
    }
}
