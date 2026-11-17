<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RubricStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\RubricFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rúbrica de evaluación de un evento y un tipo de propuesta (plan 011,
 * H-011-1). Cada edición de una rúbrica ya usada es una versión nueva; una
 * versión con evaluaciones registradas no cambia (DA-2).
 *
 * Sin `$fillable`: se crea y se cambia solo desde las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $submission_type_id
 * @property int $version
 * @property RubricStatus $status
 * @property bool $blind_review
 * @property int $min_reviewers
 * @property int $discrepancy_threshold
 */
class Rubric extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<RubricFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => RubricStatus::class,
            'blind_review' => 'boolean',
            'min_reviewers' => 'integer',
            'discrepancy_threshold' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<SubmissionType, $this> */
    public function submissionType(): BelongsTo
    {
        return $this->belongsTo(SubmissionType::class);
    }

    /** @return HasMany<RubricCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class)->orderBy('position');
    }

    public function isDraft(): bool
    {
        return $this->status === RubricStatus::Draft;
    }
}
