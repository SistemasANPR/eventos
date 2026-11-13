<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionOrigin;
use App\Enums\SubmissionStatus;
use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Propuesta de un evento (plan 006; origen `invited` del plan 018). Es evidencia:
 * conserva el aviso aceptado y una instantánea del formulario enviado, y no se
 * borra físicamente.
 *
 * Sin `$fillable`: se crea y se edita solo desde las Actions, que fijan cada campo
 * de forma explícita (el estado, el origen y la organización nunca vienen del cliente).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event_id
 * @property SubmissionOrigin $origin
 * @property string|null $call_for_submissions_id nulo solo en `invited`
 * @property string $submission_type_id
 * @property string|null $registered_by_user_id
 * @property Carbon|null $authorization_declared_at
 * @property string $form_definition_id
 * @property int $form_version
 * @property array<string, mixed> $form_snapshot
 * @property string $track_id
 * @property string $title
 * @property array<string, mixed> $answers
 * @property SubmissionStatus $status
 * @property string $locale
 * @property string|null $idempotency_key
 * @property string|null $payload_hash
 * @property string|null $privacy_notice_version_id
 * @property Carbon|null $consented_at
 * @property Carbon|null $third_parties_authorized_at
 * @property Carbon $submitted_at
 * @property Carbon|null $withdrawn_at
 * @property int $version
 */
class Submission extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['idempotency_key', 'payload_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origin' => SubmissionOrigin::class,
            'status' => SubmissionStatus::class,
            'form_version' => 'integer',
            'form_snapshot' => 'array',
            'answers' => 'array',
            'version' => 'integer',
            'authorization_declared_at' => 'datetime',
            'consented_at' => 'datetime',
            'third_parties_authorized_at' => 'datetime',
            'submitted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<CallForSubmissions, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallForSubmissions::class, 'call_for_submissions_id');
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

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    /** @return BelongsTo<PrivacyNotice, $this> */
    public function privacyNotice(): BelongsTo
    {
        return $this->belongsTo(PrivacyNotice::class, 'privacy_notice_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /** @return HasMany<SubmissionParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(SubmissionParticipant::class)->orderBy('position');
    }

    /** @return HasMany<SubmissionAccessToken, $this> */
    public function accessTokens(): HasMany
    {
        return $this->hasMany(SubmissionAccessToken::class);
    }
}
