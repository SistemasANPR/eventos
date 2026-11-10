<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallStatus;
use App\Enums\SubmissionType;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\CallForSubmissionsFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Translatable\HasTranslations;

/**
 * Convocatoria de propuestas de un evento (plan 004). `status` lo cambia una
 * persona; el estado que ve el público se calcula con `ResolveCallState` a
 * partir de `status` y de las fechas. Fechas en UTC.
 *
 * Sin `$fillable`: se crea y se edita solo desde las Actions, que fijan cada
 * campo de forma explícita.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property string $slug
 * @property SubmissionType $submission_type
 * @property list<string> $locales
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 * @property Carbon|null $results_at
 * @property CallStatus $status
 */
class CallForSubmissions extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<CallForSubmissionsFactory> */
    use HasFactory, HasTranslations, HasUuids, SoftDeletes;

    protected $table = 'call_for_submissions';

    /** @var list<string> */
    public array $translatable = ['title', 'description'];

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submission_type' => SubmissionType::class,
            'status' => CallStatus::class,
            'locales' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'results_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
