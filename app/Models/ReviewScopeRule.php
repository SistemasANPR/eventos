<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReviewAssignmentScope;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ReviewScopeRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regla permanente de alcance (plan 011, F3b, DA-6): un evaluador asignado a un eje o a una convocatoria
 * recibe cada propuesta que llegue después dentro del alcance. Tabla `review_assignment_scopes`; el modelo se
 * llama distinto del enum `ReviewAssignmentScope` (el tipo de alcance) para no confundirlos. Sin `$fillable`:
 * se crea y se quita solo desde las Actions (`AssignReviewers`, `RemoveScopeRule`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $reviewer_id
 * @property ReviewAssignmentScope $scope_type solo `track` o `call`
 * @property string|null $track_id
 * @property string|null $call_id
 * @property string $created_by
 */
class ReviewScopeRule extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<ReviewScopeRuleFactory> */
    use HasFactory, HasUuids;

    protected $table = 'review_assignment_scopes';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope_type' => ReviewAssignmentScope::class];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<CallForSubmissions, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallForSubmissions::class, 'call_id');
    }

    /** El identificador del alcance, sea eje o convocatoria. */
    public function scopeId(): string
    {
        return (string) ($this->scope_type === ReviewAssignmentScope::Track ? $this->track_id : $this->call_id);
    }
}
