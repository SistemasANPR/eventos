<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReviewRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versión anterior de una evaluación, conservada al reevaluar (plan 011, CA-011-15): su número de envío,
 * la versión de rúbrica con que se hizo y una foto de sus respuestas. Solo se inserta; nunca se cambia.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $review_id
 * @property string $rubric_id
 * @property int $revision
 * @property Carbon $submitted_at
 * @property list<array{rubric_criterion_id: string, value_int: int|null, value_text: string|null}> $answers
 * @property Carbon $created_at
 */
class ReviewRevision extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ReviewRevisionFactory> */
    use HasFactory, HasUuids;

    protected $table = 'review_history';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'submitted_at' => 'datetime',
            'answers' => 'array',
        ];
    }

    /** @return BelongsTo<Review, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
