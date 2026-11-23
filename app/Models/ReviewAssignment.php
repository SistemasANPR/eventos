<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewAssignmentSource;
use App\Enums\ReviewAssignmentStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReviewAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Asignación de un evaluador a una propuesta (plan 011, H-011-2). Única por propuesta y evaluador.
 * Sin `$fillable`: se crea y se cambia solo desde las Actions (`AssignReviewers`, `RemoveReviewAssignment`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $submission_id
 * @property string $reviewer_id
 * @property ReviewAssignmentStatus $status
 * @property string|null $assigned_by nulo solo si asignó el sistema (regla de alcance, F3b)
 * @property Carbon $assigned_at
 * @property ReviewAssignmentSource $source
 */
class ReviewAssignment extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ReviewAssignmentFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReviewAssignmentStatus::class,
            'source' => ReviewAssignmentSource::class,
            'assigned_at' => 'datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
