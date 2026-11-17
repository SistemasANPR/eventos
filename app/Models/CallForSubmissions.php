<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallStatus;
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
 * @property string $submission_type_id
 * @property list<string> $locales
 * @property Carbon $opens_at
 * @property Carbon $closes_at
 * @property Carbon|null $results_at
 * @property CallStatus $status
 * @property int $min_participants mínimo de participantes por propuesta (plan 006; por defecto 1)
 * @property int $max_participants máximo de participantes por propuesta (plan 006; por defecto 5)
 * @property string|null $form_definition_id formulario de propuestas; nulo hasta que se publica uno (plan 005)
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
            'status' => CallStatus::class,
            'locales' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'results_at' => 'datetime',
            'min_participants' => 'integer',
            'max_participants' => 'integer',
        ];
    }

    /** @return BelongsTo<SubmissionType, $this> */
    public function submissionType(): BelongsTo
    {
        return $this->belongsTo(SubmissionType::class);
    }

    /** @return BelongsTo<FormDefinition, $this> */
    public function formDefinition(): BelongsTo
    {
        return $this->belongsTo(FormDefinition::class);
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
