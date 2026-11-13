<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubmissionAccessTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Enlace de acceso de un solo uso del autor (plan 006, DA-3). Solo existe el hash
 * SHA-256 del token; el valor en claro nunca se guarda. Sin `$fillable`: lo crean
 * solo las Actions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $submission_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class SubmissionAccessToken extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<SubmissionAccessTokenFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
