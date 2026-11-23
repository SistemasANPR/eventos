<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionStatusChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un cambio de estado de una propuesta (plan 012, §4): de qué a qué, quién, cuándo y con qué comentario.
 * Solo se añade (no hay `updated_at` ni borrado). Es dato de negocio consultable, distinto de la
 * bitácora de auditoría.
 *
 * Sin `$fillable`: se crea solo desde `ChangeSubmissionStatus`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $submission_id
 * @property SubmissionStatus $from_status
 * @property SubmissionStatus $to_status
 * @property string|null $actor_user_id nulo si lo hizo el sistema
 * @property string|null $comment motivo interno
 * @property string|null $author_comment comentario para el autor
 * @property string|null $decision_batch_id
 * @property Carbon $created_at
 */
class SubmissionStatusChange extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionStatusChangeFactory> */
    use HasFactory, HasUuids;

    /** Longitud máxima del comentario (la base lo vigila con un `CHECK`). */
    public const COMMENT_MAX = 1000;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_status' => SubmissionStatus::class,
            'to_status' => SubmissionStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
