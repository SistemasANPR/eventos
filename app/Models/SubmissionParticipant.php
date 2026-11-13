<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Participante de una propuesta (plan 006, DA-2): instantánea de sus datos al enviar,
 * vinculada a un `Contact` sin modificarlo. Sin `$fillable`: lo crean solo las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $contact_id
 * @property int $position
 * @property bool $is_primary
 * @property string $full_name
 * @property string $email
 * @property string $country
 * @property string|null $job_title
 * @property string|null $company
 * @property string|null $bio
 */
class SubmissionParticipant extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionParticipantFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
