<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\DecisionBatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lote de una decisión masiva (plan 012, F2, H-012-2): quién decidió, cuándo, con qué selección (el filtro
 * aplicado, sin datos personales) y cuántas propuestas. Solo se añade: no se edita ni se borra; los
 * cambios de estado del lote lo referencian (`submission_status_changes.decision_batch_id`).
 *
 * Sin `$fillable`: se crea solo desde `BulkDecideSubmissions`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $actor_user_id
 * @property array<string, mixed> $filter
 * @property int $total
 * @property Carbon $created_at
 */
class DecisionBatch extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DecisionBatchFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'total' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /** @return HasMany<SubmissionStatusChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(SubmissionStatusChange::class, 'decision_batch_id');
    }
}
