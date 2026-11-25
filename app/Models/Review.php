<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Evaluación de un evaluador sobre una propuesta (plan 011, H-011-3). Una por asignación; guarda la
 * versión de la rúbrica con que se hizo. `revision` cuenta los envíos (0 = borrador sin enviar) y
 * `submitted_at` es el instante UTC del último. Es evidencia: borrado lógico.
 *
 * Sin `$fillable`: se crea y se cambia solo desde las Actions (`SubmitReview`, `SaveReviewDraft`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $review_assignment_id
 * @property string $rubric_id
 * @property Carbon|null $submitted_at
 * @property int $revision
 */
class Review extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ReviewFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'revision' => 'integer',
        ];
    }

    /** @return BelongsTo<ReviewAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReviewAssignment::class, 'review_assignment_id');
    }

    /** @return BelongsTo<Rubric, $this> */
    public function rubric(): BelongsTo
    {
        return $this->belongsTo(Rubric::class);
    }

    /** @return HasMany<ReviewAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(ReviewAnswer::class);
    }

    /** @return HasMany<ReviewRevision, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(ReviewRevision::class)->orderBy('revision');
    }
}
