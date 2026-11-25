<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReviewAnswerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Respuesta de una evaluación a un criterio de la rúbrica (plan 011): un entero dentro de la escala del
 * criterio de calificación, o un texto del criterio de comentario. Sin `$fillable`: la escriben las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $review_id
 * @property string $rubric_criterion_id
 * @property int|null $value_int
 * @property string|null $value_text
 */
class ReviewAnswer extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ReviewAnswerFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value_int' => 'integer'];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    /** @return BelongsTo<RubricCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(RubricCriterion::class, 'rubric_criterion_id');
    }
}
